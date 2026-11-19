<?php

declare(strict_types=1);

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Enums\ActivityType;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Settings\BusinessSettings;
use App\Support\Api\Problem;
use App\Support\Phone;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Bearer capability for one enquiry's manual handoff, not proof of identity or message delivery. */
final class ManualWhatsAppHandoff
{
    public function __construct(private readonly BusinessSettings $business) {}

    /**
     * With the key of the request that made the enquiry (/api/v1, App\Support\Api\Idempotency), the token is made
     * from that key and can be made again: the same request sent twice is answered with the same token, and the
     * token itself is kept nowhere, only its hash.
     *
     * @return array{token: string, expires_at: CarbonInterface}|null
     */
    public function issue(Lead $lead, ?string $requestKey = null): ?array
    {
        if ($lead->business_line !== BusinessLine::Furniture || $this->destination() === null) {
            return null;
        }

        $token = $requestKey === null ? bin2hex(random_bytes(32)) : $this->tokenFor($lead->id, $requestKey);
        $expires = now()->addMinutes(15);
        DB::table('lead_handoff_capabilities')->insert([
            'lead_id' => $lead->id, 'token_hash' => hash('sha256', $token),
            'expires_at' => $expires, 'created_at' => now(),
        ]);

        return ['token' => $token, 'expires_at' => $expires];
    }

    /**
     * The token of the hand-off that the request with this key was given for this enquiry. Nobody can work it out
     * without the application's own key, and the request's key alone opens nothing.
     */
    public function tokenFor(int $leadId, string $requestKey): string
    {
        return hash_hmac('sha256', 'whatsapp-handoff|'.$leadId.'|'.Str::lower(trim($requestKey)), (string) config('app.key'));
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
            if ($destination === null) {
                throw new Problem(503, 'UNAVAILABLE', 'WhatsApp hand-off is not available at the moment.');
            }

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
