<?php

declare(strict_types=1);

namespace App\Domain\Sales\Actions;

use App\Domain\Automation\Actions\EnrollLead;
use App\Domain\Automation\Enums\SequenceTrigger;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteEvent;
use App\Domain\Sales\Models\QuoteVersion;
use App\Domain\Sales\Notifications\QuoteNotification;
use App\Domain\Sales\Services\QuoteLinks;
use App\Domain\Sales\Services\QuoteWriteLock;
use App\Domain\System\Alerts\AlertRouter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tracks a client opening the quotation link (QUO-010): every view is an event (time, device, IP); the first view
 * goes on the timeline and alerts the team; 3 views within 24 hours make it "hot" (alert at most once a day).
 */
final class RecordQuoteView
{
    public const int HOT_VIEWS = 3;

    public function __construct(private readonly LogQuoteEvent $log) {}

    public function handle(Quote $quote, QuoteVersion $version, ?string $ip, ?string $userAgent, string $type = 'viewed'): void
    {
        DB::transaction(function () use ($quote, $version, $ip, $userAgent, $type): void {
            QuoteWriteLock::refresh($quote);
            if ($version->quote_id !== $quote->id) {
                throw new \InvalidArgumentException('The version belongs to a different quotation.');
            }

            $now = now();
            $first = $quote->first_viewed_at === null;
            $attributes = [
                'quote_version_id' => $version->id,
                'channel' => 'link',
                'ip' => $ip,
                'user_agent' => Str::limit((string) $userAgent, 250, ''),
            ];
            $data = ['device' => self::device($userAgent), 'revision' => $version->revision];

            if ($type === 'viewed') {
                $quote->forceFill([
                    'first_viewed_at' => $quote->first_viewed_at ?? $now,
                    'last_viewed_at' => $now,
                    'view_count' => $quote->view_count + 1,
                ]);

                if ($quote->status === QuoteStatus::Sent) {
                    $quote->status = QuoteStatus::Viewed;
                }

                $quote->save();
            }

            if ($first && $type === 'viewed' && $quote->lead_id !== null) {
                // AUT-001: the client read it — the "seen but silent" sequences start from here.
                $lead = Lead::query()->find($quote->lead_id);

                if ($lead !== null) {
                    app(EnrollLead::class)->forTrigger(SequenceTrigger::QuotationViewed, $lead);
                }
            }

            if ($first && $type === 'viewed') {
                $this->log->handle($quote, 'viewed', null, "{$quote->contact->name} opened {$version->snapshot['label']} for the first time ({$data['device']}).", $data, $attributes);
                app(AlertRouter::class)->send('quote.viewed', QuoteLinks::watchers($quote), new QuoteNotification($quote, 'first_view', 'Opened just now on '.$data['device'].'.'));

                return;
            }

            QuoteEvent::create(['quote_id' => $quote->id, 'type' => $type, 'data' => $data, 'occurred_at' => $now, ...$attributes]);

            if ($type !== 'viewed') {
                return;
            }

            $recent = QuoteEvent::query()->where('quote_id', $quote->id)->where('type', 'viewed')->where('occurred_at', '>=', $now->copy()->subDay())->count();
            $lastHot = $quote->meta['hot_notified_at'] ?? null;

            if ($recent >= self::HOT_VIEWS && ($lastHot === null || $now->copy()->subDay()->gt($lastHot))) {
                $quote->update(['meta' => [...($quote->meta ?? []), 'hot_notified_at' => $now->toIso8601String()]]);
                app(AlertRouter::class)->send('quote.viewed', QuoteLinks::watchers($quote), new QuoteNotification($quote, 'hot', "{$recent} views in the last 24 hours: a good moment to call."));
            }
        });
    }

    public static function device(?string $userAgent): string
    {
        $ua = (string) $userAgent;

        $device = match (true) {
            (bool) preg_match('/iPad|Tablet/i', $ua) => 'tablet',
            (bool) preg_match('/Mobile|Android|iPhone/i', $ua) => 'phone',
            default => 'computer',
        };

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            str_contains($ua, 'Firefox/') => 'Firefox',
            default => 'browser',
        };

        return "{$device} · {$browser}";
    }
}
