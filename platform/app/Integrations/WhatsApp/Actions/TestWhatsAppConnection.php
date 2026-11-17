<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp\Actions;

use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Settings\IntegrationSettings;
use Throwable;

/**
 * The "Test connection" button on the WhatsApp card (WAP-001).
 *
 * Two questions, in order. First: does Meta recognise this token and phone number? That is answered by asking
 * Meta what it knows about the number — the name it verified, the number itself, and the quality rating.
 * Second, if the studio gave a number: does a message actually arrive there? Credentials that are merely valid
 * have never once delivered a message, so with a number this sends one and reports the delivery.
 *
 * Whatever Meta tells us about the number is remembered, so the card can say which number it is sending from.
 */
final class TestWhatsAppConnection
{
    public function __construct(
        private readonly WhatsAppGateway $whatsapp,
        private readonly IntegrationSettings $settings,
    ) {}

    /**
     * @return array{ok: bool, name: ?string, phone: ?string, quality: ?string, probe: ?bool, error: ?string}
     */
    public function handle(?string $probeNumber = null): array
    {
        $nothing = ['ok' => false, 'name' => null, 'phone' => null, 'quality' => null, 'probe' => null, 'error' => 'WhatsApp is not connected yet.'];

        if (! $this->whatsapp->isConfigured()) {
            return $nothing;
        }

        $info = $this->whatsapp->accountInfo();

        if (! $info['ok']) {
            return [...$nothing, 'error' => $info['error'] ?? 'Meta did not recognise the token and phone number.'];
        }

        $this->remember($info);

        $number = is_string($probeNumber) ? trim($probeNumber) : '';

        if ($number === '') {
            return ['ok' => true, 'name' => $info['verified_name'], 'phone' => $info['display_phone_number'], 'quality' => $info['quality_rating'], 'probe' => null, 'error' => null];
        }

        $result = $this->whatsapp->sendText($number, '✅ Woodex OS test message. WhatsApp is connected to this number.');

        return [
            'ok' => $result->ok,
            'name' => $info['verified_name'],
            'phone' => $info['display_phone_number'],
            'quality' => $info['quality_rating'],
            'probe' => $result->ok,
            'error' => $result->ok ? null : ($result->error ?? 'Meta refused the test message.'),
        ];
    }

    /**
     * @param  array{ok: bool, display_phone_number: ?string, verified_name: ?string, quality_rating: ?string, error: ?string}  $info
     */
    private function remember(array $info): void
    {
        try {
            $this->settings->whatsapp_verified_name = $info['verified_name'];
            $this->settings->whatsapp_display_phone = $info['display_phone_number'];
            $this->settings->whatsapp_quality_rating = $info['quality_rating'];
            $this->settings->save();
        } catch (Throwable) {
            // Settings unavailable (a deploy is half way through, or the table is not there yet): the connection
            // itself is proven, and the card can live without remembering the number's name.
        }
    }
}
