<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Settings\BusinessSettings;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;

/** Bearer capability for one enquiry's manual handoff, not proof of identity or message delivery. */
final class ManualWhatsAppHandoff
{
    public function __construct(private readonly BusinessSettings $business) {}

    /** @return array{token: string, expires_at: string}|null */
    public function issue(Lead $lead): ?array
    {
        if ($lead->business_line !== BusinessLine::Furniture || $this->destination() === null) {
            return null;
        }

        $token = bin2hex(random_bytes(32));
        $expires = now()->addMinutes(15);
        DB::table('lead_handoff_capabilities')->insert([
            'lead_id' => $lead->id, 'token_hash' => hash('sha256', $token),
            'expires_at' => $expires, 'created_at' => now(),
        ]);

        return ['token' => $token, 'expires_at' => $expires->toIso8601String()];
    }

    /** @return array{logged: bool, delivery: string, whatsapp_url: string} */
    public function redeem(string $token): array
    {
        return DB::transaction(function () use ($token): array {
            $capability = DB::table('lead_handoff_capabilities')
                ->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();
            // Same response for unknown, expired and deleted leads; no public record lookup.
            abort_if($capability === null || $capability->expires_at <= now()->toDateTimeString(), 404);
            $lead = Lead::query()->find($capability->lead_id);
            abort_if($lead === null || $lead->business_line !== BusinessLine::Furniture, 404);
            $destination = $this->destination();
            abort_if($destination === null, 503, 'WhatsApp handoff is currently unavailable.');

            // Atomic claim plus timeline insert in one transaction. Replays are idempotent until expiry.
            $claimed = DB::table('lead_handoff_capabilities')->where('id', $capability->id)
                ->whereNull('redeemed_at')->update(['redeemed_at' => now()]);
            if ($claimed === 1) {
                Activity::create([
                    'lead_id' => $lead->id, 'contact_id' => $lead->contact_id,
                    'type' => ActivityType::System,
                    'body' => 'Manual WhatsApp handoff prepared — no message sent',
                    'direction' => 'out',
                    'data' => ['delivery' => 'manual_handoff_not_sent', 'source' => 'storefront_v1'],
                    'occurred_at' => now(),
                ]);
            }

            return [
                'logged' => true, 'delivery' => 'manual_handoff_not_sent',
                'whatsapp_url' => Phone::whatsappUrl($destination, "Hello Woodex, my enquiry reference is {$lead->ref}. Please follow up with me."),
            ];
        });
    }

    private function destination(): ?string
    {
        return Phone::normalize($this->business->whatsapp);
    }
}
