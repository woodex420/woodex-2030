<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp\Actions;

use App\Domain\System\Alerts\AlertRouter;
use App\Domain\System\Notifications\WhatsAppQualityNotification;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Models\User;
use App\Settings\IntegrationSettings;

/**
 * WAP-010: watch Meta's quality rating for the studio's number.
 *
 * Runs daily. The rating is stored whenever the connection is tested; this check notices a
 * downgrade (GREEN → YELLOW → RED) the same day it happens, tells everyone who manages
 * integrations, and always keeps the stored rating current. Meta does not expose the
 * messaging-limit tier on the phone-number node, so that half of WAP-010 waits for an
 * honest API surface rather than a guess.
 *
 * @return array{ok: bool, rating: ?string, previous: ?string, downgraded: bool, error: ?string}
 */
final class CheckWhatsAppQuality
{
    private const RANK = ['GREEN' => 3, 'YELLOW' => 2, 'RED' => 1];

    public function __construct(
        private readonly WhatsAppGateway $gateway,
        private readonly IntegrationSettings $settings,
    ) {}

    /**
     * @return array{ok: bool, rating: ?string, previous: ?string, downgraded: bool, error: ?string}
     */
    public function handle(): array
    {
        if (! $this->gateway->isConfigured()) {
            return ['ok' => false, 'rating' => null, 'previous' => null, 'downgraded' => false, 'error' => 'WhatsApp is not connected.'];
        }

        $info = $this->gateway->accountInfo();

        if (! ($info['ok'] ?? false)) {
            return ['ok' => false, 'rating' => null, 'previous' => null, 'downgraded' => false, 'error' => $info['error'] ?? 'Meta did not answer.'];
        }

        $rating = is_string($info['quality_rating'] ?? null) && $info['quality_rating'] !== '' ? strtoupper($info['quality_rating']) : null;
        $previous = $this->settings->whatsapp_quality_rating;

        $downgraded = $rating !== null
            && $previous !== null
            && isset(self::RANK[$rating], self::RANK[$previous])
            && self::RANK[$rating] < self::RANK[$previous];

        if ($rating !== $previous) {
            $this->settings->whatsapp_quality_rating = $rating;
            $this->settings->save();
        }

        if ($downgraded) {
            $recipients = User::query()
                ->with('telegramLink')
                ->where('is_active', true)
                ->permission('integrations.manage')
                ->get();

            if ($recipients->isNotEmpty()) {
                app(AlertRouter::class)->send('system.whatsapp_quality', $recipients, new WhatsAppQualityNotification($previous, $rating));
            }
        }

        return ['ok' => true, 'rating' => $rating, 'previous' => $previous, 'downgraded' => $downgraded, 'error' => null];
    }
}
