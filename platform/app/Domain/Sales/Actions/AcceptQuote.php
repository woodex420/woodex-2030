<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Crm\Actions\MoveLeadToStage;
use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Projects\Actions\OpenProjectFromQuote;
use App\Domain\Sales\Enums\AcceptanceChannel;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Notifications\QuoteNotification;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\System\Alerts\AlertRouter;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The client accepts on the quotation page (QUO-009/011): typed name + confirmation. The quote is accepted, the
 * contact becomes a client, the lead moves to Won, the project is opened and the advance invoice is raised — all in
 * one transaction. Recording the payment against that invoice is P3.
 *
 * Three ways in. The client's own page (no member of staff: `$by` is null). A WhatsApp reply that sounded like a
 * yes and that a member of staff confirms (`$by`, no channel). And an answer that reached the business some other
 * way — a call, a visit, an e-mail, a signed copy — which a member of staff records, naming who said yes and how
 * (`$by` and `$channel`). The last one is the only way while the client's page is switched off.
 */
final class AcceptQuote
{
    public function __construct(
        private readonly LogQuoteEvent $log,
        private readonly MoveLeadToStage $moveLead,
        private readonly OpenProjectFromQuote $openProject,
        private readonly RaiseAdvanceInvoice $raiseInvoice,
        private readonly SetLeadValueFromQuote $leadValue,
    ) {}

    public function handle(Quote $quote, string $name, ?string $phone, ?string $ip, ?string $userAgent, ?User $by = null, ?int $revision = null, ?AcceptanceChannel $channel = null, ?string $note = null): Quote
    {
        $expectedRevision = $revision ?? (int) $quote->revision;
        $original = $quote;
        $note = $by !== null && $channel !== null && filled($note) ? trim((string) $note) : null;

        DB::transaction(function () use ($quote, $name, $phone, $ip, $userAgent, $by, $expectedRevision, $channel, $note): void {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            // A member of staff answers for the client only with the right to: asked again here, on the row as it
            // is now, so that no caller can forget to.
            if ($by !== null) {
                $by = User::query()->findOrFail($by->id);
                abort_unless($by->is_active, 403);
                Gate::forUser($by)->authorize('accept', $quote);
            }

            if ((int) $quote->revision !== $expectedRevision) {
                throw new QuoteActionDenied('This quotation has changed. Reload the page and review the current revision before accepting.');
            }

            if (! in_array($quote->status, [QuoteStatus::Sent, QuoteStatus::Viewed], true)) {
                throw new QuoteActionDenied($by === null
                    ? 'This quotation can no longer be accepted online. Please contact us.'
                    : "{$quote->label()} is {$quote->status->label()}: only a quotation that is with the client can be accepted.");
            }

            if ($quote->valid_until !== null && $quote->valid_until->lt(today())) {
                throw new QuoteActionDenied($by === null
                    ? 'This quotation expired on '.$quote->valid_until->format('j M Y').'. Ask us for an updated one.'
                    : "{$quote->label()} expired on ".$quote->valid_until->format('j M Y').': revise it and send it again before an acceptance is recorded.');
            }

            $quote->update([
                'status' => QuoteStatus::Accepted,
                'accepted_at' => now(),
                'accepted_name' => trim($name),
                'accepted_phone' => Phone::normalize($phone),
                'accepted_ip' => $ip,
            ]);

            $this->log->handle($quote, 'accepted', $by, match (true) {
                $by === null => "{$quote->contact->name} accepted {$quote->label()} online as “".trim($name).'”.',
                $channel === null => "{$by->name} accepted {$quote->label()}: the client said yes on WhatsApp.",
                default => "{$by->name} recorded that “".trim($name)."” accepted {$quote->label()} {$channel->phrase()}.".($note !== null ? " Note: {$note}" : ''),
            }, [
                'name' => trim($name),
                'total_minor' => (int) $quote->total_minor,
                ...($by !== null && $channel !== null ? ['recorded_by_staff' => true] : []),
                ...($note !== null ? ['note' => $note] : []),
            ], ['channel' => $by === null ? 'link' : ($channel?->value ?? 'whatsapp'), 'ip' => $ip, 'user_agent' => mb_substr((string) $userAgent, 0, 250)]);

            $contact = $quote->contact()->lockForUpdate()->firstOrFail();
            if (! $contact->is_client) {
                $contact->update(['is_client' => true, 'became_client_at' => now()]);
            }

            // What was accepted is what the lead is worth: the board's "Won" then adds up to the sales made.
            $this->leadValue->accepted($quote);

            $lead = $quote->lead;
            $won = PipelineStage::query()->where('type', StageType::Won)->first();
            if ($lead !== null && $won !== null && $lead->won_at === null && $lead->lost_at === null) {
                $this->moveLead->handle($lead, $won);
            }

            // The job starts here: the project carries the work, the advance invoice asks for its first payment.
            $project = $this->openProject->handle($quote);
            $invoice = $this->raiseInvoice->handle($quote);

            if ($invoice !== null) {
                // The project goes in the event's data. It used to be passed as a column of the event, which the
                // table does not have, so it was dropped without a word and the event never named its project.
                $this->log->handle($quote, 'invoiced', null, sprintf(
                    'Advance invoice %s of %s raised for project %s.',
                    $invoice->number,
                    $invoice->total(),
                    $project->code,
                ), ['invoice_id' => $invoice->id, 'total_minor' => (int) $invoice->total_minor, 'project_id' => $project->id]);
            }
        });

        $quote = $original->refresh();
        $invoice = $quote->invoices()->latest('id')->first();

        app(AlertRouter::class)->send('quote.accepted', QuoteLinks::watchers($quote), new QuoteNotification($quote, 'accepted', (match (true) {
            $by === null => 'Accepted online by “'.trim($name).'”. ',
            $channel === null => "Accepted on WhatsApp, confirmed by {$by->name}. ",
            default => 'Accepted '.$channel->phrase().' by “'.trim($name)."”, recorded by {$by->name}. ",
        }).(
            $invoice === null
                ? (($quote->meta['furniture_billing'] ?? '') === 'full' ? 'Furniture full billing: create and review the full invoice from Sales; no milestone invoice is raised.' : 'No advance milestone: raise the first invoice when the work reaches one.')
                : (($quote->meta['furniture_billing'] ?? '') === 'full' ? 'Full Furniture invoice ' : 'Advance invoice ').$invoice->number.' ('.$invoice->total()->format().') is '.$invoice->status->label().'.'
        )));

        return $quote;
    }
}
