<?php

declare(strict_types=1);

namespace App\Integrations\Telegram;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Slice L: the pairing artefacts for the one-time code on a TelegramLink — the deep link a phone opens
 * (https://t.me/<bot>?start=<code>) and the QR of that same link, rendered on our own server with the
 * bacon encoder the authenticator setup already uses. No third-party QR image service ever sees a code.
 */
final class TelegramPairing
{
    /**
     * The bot a pairing link opens, or null when TELEGRAM_BOT_USERNAME is empty — a link with no bot
     * in it (https://t.me/?start=…) opens nothing, so callers must treat null as "cannot pair".
     */
    public function botUsername(): ?string
    {
        $username = ltrim(trim((string) config('services.telegram.bot_username')), '@');

        return $username === '' ? null : $username;
    }

    public function startUrl(string $code): ?string
    {
        $username = $this->botUsername();

        if ($username === null || $code === '') {
            return null;
        }

        return 'https://t.me/'.rawurlencode($username).'?start='.rawurlencode($code);
    }

    public function qrSvg(string $url, int $size = 192): string
    {
        $svg = (new Writer(new ImageRenderer(
            new RendererStyle($size, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(45, 55, 72))),
            new SvgImageBackEnd,
        )))->writeString($url);

        $firstLineEnd = strpos($svg, "\n");

        return trim($firstLineEnd === false ? $svg : substr($svg, $firstLineEnd + 1));
    }
}
