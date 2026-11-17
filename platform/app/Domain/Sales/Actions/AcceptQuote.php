<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Crm\Actions\MoveLeadToStage;
use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Projects\Actions\OpenProjectFromQuote;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Notifications\QuoteNotification;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\System\Alerts\AlertRouter;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

/**
 * The client accepts on the quotation page (QUO-009/011): typed name + confirmation. The quote is accepted, the
 * contact becomes a client, the lead moves to Won, the project is opened and the advance invoice is raised — all in
 * one transaction. Recording the payment against that invoice is P3.
 */
final class AcceptQuote
{
    public function __construct(
        private readonly LogQuoteEvent $log,
        private readonly MoveLeadToStage $moveLead,
        private readonly OpenProjectFromQuote $openProject,
        private readonly RaiseAdvanceInvoice $raiseInvoice,
    ) {}

    public function handle(Quote $quote, string $name, ?string $phone, ?string $ip, ?string $userAgent, ?User $by = null, ?int $revision = null): Quote
    {
        $expectedRevision = $revision ?? (int) $quote->revision;
        $original = $quote;

        DB::transaction(function () use ($quote, $name, $phone, $ip, $userAgent, $by, $expectedRevision): void {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ((int) $quote->revision !== $expectedRevision) {
                throw new QuoteActionDenied('This quotation has changed. Reload the page and review the current revision before accepting.');
            }

            if (! in_array($quote->status, [QuoteStatus::Sent, QuoteStatus::Viewed], true)) {
                throw new QuoteActionDenied('This quotation can no longer be accepted online. Please contact us.');
            }

            if ($quote->valid_until !== null && $quote->valid_until->lt(today())) {
                throw new QuoteActionDenied('This quotation expired on '.$quote->valid_until->format('j M Y').'. Ask us for an updated one.');
            }

            $quote->update([
                'status' => QuoteStatus::Accepted,
                'accepted_at' => now(),
                'accepted_name' => trim($name),
                'accepted_phone' => Phone::normalize($phone),
                'accepted_ip' => $ip,
            ]);

            $this->log->handle($quote, 'accepted', $by, $by === null
                ? "{$quote->contact->name} accepted {$quote->label()} online as “".trim($name).'”.'
                : "{$by->name} accepted {$quote->label()}: the client said yes on WhatsApp.", [
                    'name' => trim($name),
                    'total_minor' => (int) $quote->total_minor,
                ], ['channel' => $by === null ? 'link' : 'whatsapp', 'ip' => $ip, 'user_agent' => mb_substr((string) $userAgent, 0, 250)]);

            $contact = $quote->contact()->lockForUpdate()->firstOrFail();
            if (! $contact->is_client) {
                $contact->update(['is_client' => true, 'became_client_at' => now()]);
            }

            $lead = $quote->lead;
            $won = PipelineStage::query()->where('type', StageType::Won)->first();
            if ($lead !== null && $won !== null && $lead->won_at === null && $lead->lost_at === null) {
                $this->moveLead->handle($lead, $won);
            }

            // The job starts here: the project carries the work, the advance invoice asks for its first payment.
            $project = $this->openProject->handle($quote);
            $invoice = $this->raiseInvoice->handle($quote);

            if ($invoice !== null) {
                $this->log->handle($quote, 'invoiced', null, sprintf(
                    'Advance invoice %s of %s raised for project %s.',
                    $invoice->number,
                    $invoice->total(),
                    $project->code,
                ), ['invoice_id' => $invoice->id, 'total_minor' => (int) $invoice->total_minor], ['project_id' => $project->id]);
            }
        });

        $quote = $original->refresh();
        $invoice = $quote->invoices()->latest('id')->first();

        app(AlertRouter::class)->send('quote.accepted', QuoteLinks::watchers($quote), new QuoteNotification($quote, 'accepted', ($by === null
            ? 'Accepted online by “'.trim($name).'”. '
            : "Accepted on WhatsApp, confirmed by {$by->name}. ").(
                $invoice === null
                    ? (($quote->meta['furniture_billing'] ?? '') === 'full' ? 'Furniture full billing: create and review the full invoice from Sales; no milestone invoice is raised.' : 'No advance milestone: raise the first invoice when the work reaches one.')
                    : (($quote->meta['furniture_billing'] ?? '') === 'full' ? 'Full Furniture invoice ' : 'Advance invoice ').$invoice->number.' ('.$invoice->total()->format().') is '.$invoice->status->label().'.'
            )));

        return $quote;
    }
}
