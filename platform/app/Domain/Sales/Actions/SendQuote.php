<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Events\QuoteSent;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Mail\QuoteSentMail;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteItem;
use App\Domain\Sales\Models\QuoteVersion;
use App\Domain\Sales\Services\QuoteApprovalRules;
use App\Domain\Sales\Services\QuotePdf;
use App\Domain\Sales\Services\QuoteSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Sends a quotation (QUO-008/013/014): checks it is complete and approved where needed, freezes an immutable
 * version (snapshot + PDF with its SHA-256), marks it sent and logs the event. A revision number is sent once:
 * sending again after changes moves the quote to the next revision (R1, R2…). The client link (QuoteLinks) always
 * shows the latest sent version. WhatsApp sending arrives with the API in P4; until then the link is shared by
 * copy, WhatsApp click-to-chat or email.
 */
final class SendQuote
{
    public function __construct(
        private readonly QuoteApprovalRules $rules,
        private readonly QuoteSnapshot $snapshots,
        private readonly QuotePdf $pdf,
        private readonly LogQuoteEvent $log,
    ) {}

    /**
     * @return list<string> what must be fixed before the quote can be sent
     */
    public function problems(Quote $quote): array
    {
        $items = $quote->items()->get(['id', 'qty', 'rate_minor', 'is_optional']);
        $problems = [];

        if ($items->where('is_optional', false)->isEmpty()) {
            $problems[] = 'Add at least one line that is not optional.';
        }

        $unpriced = $items->filter(fn (QuoteItem $item): bool => ! $item->is_optional && ((float) $item->qty <= 0 || $item->rate_minor <= 0))->count();
        if ($unpriced > 0) {
            $problems[] = $unpriced === 1 ? '1 line has no quantity or rate.' : "{$unpriced} lines have no quantity or rate.";
        }

        if ((int) $quote->total_minor <= 0) {
            $problems[] = 'The total must be more than zero.';
        }

        if ($quote->milestones()->exists() && (int) $quote->milestones()->sum('percent_bp') !== 10000) {
            $problems[] = 'The payment milestones must add up to 100%.';
        }

        if ($quote->valid_until === null || $quote->valid_until->lt(today())) {
            $problems[] = 'Set a "valid until" date from today onwards.';
        }

        return $problems;
    }

    public function isSendable(Quote $quote): bool
    {
        return in_array($quote->status, [QuoteStatus::Draft, QuoteStatus::Approved, QuoteStatus::ChangesRequested], true);
    }

    public function handle(Quote $quote, User $by, string $channel = 'link', bool $email = false): QuoteVersion
    {
        $expectedRevision = (int) $quote->revision;
        $original = $quote;

        $version = DB::transaction(function () use ($quote, $by, $channel, $email, $expectedRevision): QuoteVersion {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();
            $by = User::query()->findOrFail($by->id);
            abort_unless($by->is_active, 403);
            Gate::forUser($by)->authorize('send', $quote);

            if ((int) $quote->revision !== $expectedRevision) {
                throw new QuoteActionDenied('This quotation has changed. Reload it and review the current revision before sending.');
            }

            if (! $this->isSendable($quote)) {
                throw new QuoteActionDenied("{$quote->label()} is {$quote->status->label()}: use \"Revise\" to send a changed version.");
            }

            if (($problems = $this->problems($quote)) !== []) {
                throw ValidationException::withMessages(['send' => $problems]);
            }

            $assessment = $this->rules->assess($quote, $by);
            if ($assessment->needed && ! $this->rules->isApprovedAsIs($quote)) {
                throw QuoteActionDenied::needsApproval($assessment);
            }

            if ($quote->versions()->where('revision', $quote->revision)->exists()) {
                $quote->update(['revision' => $quote->revision + 1]);
            }

            $snapshot = $this->snapshots->build($quote);
            $bytes = $this->pdf->render($snapshot);
            $path = 'quotes/'.$quote->public_id.'/'.QuotePdf::filename($snapshot);
            if (! Storage::disk('local')->put($path, $bytes)) {
                throw new \RuntimeException('The quotation PDF could not be stored.');
            }

            $version = QuoteVersion::create([
                'quote_id' => $quote->id,
                'revision' => $quote->revision,
                'snapshot' => $snapshot,
                'total_minor' => (int) $quote->total_minor,
                'pdf_path' => $path,
                'pdf_sha256' => hash('sha256', $bytes),
                'created_by' => $by->id,
                'sent_at' => now(),
            ]);

            $quote->update(['status' => QuoteStatus::Sent, 'sent_at' => now()]);

            $this->log->handle($quote, 'sent', $by, "{$quote->label()} sent to {$quote->contact->name}".($email ? ' (link + email)' : ' (link)').'.', [
                'revision' => (int) $quote->revision,
                'total_minor' => (int) $quote->total_minor,
                'email' => $email,
            ], ['quote_version_id' => $version->id, 'channel' => $channel]);

            return $version;
        });

        $quote = $original->refresh();

        QuoteSent::dispatch($quote, $version, $channel);

        if ($email && filled($quote->contact->email)) {
            Mail::to((string) $quote->contact->email)->queue(new QuoteSentMail($quote->id, $version->id));
        }

        return $version;
    }
}
