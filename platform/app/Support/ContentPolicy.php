<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Vite;

/**
 * The Content-Security-Policy every page of the product is sent with (master list T-068, defect D-012).
 *
 * It tells the browser what a page may load and run. A script that somebody managed to put into a page — in a
 * name, a note, a message — is then not run, a page cannot be made to send what it shows to another server, and no
 * other site can put the admin inside a frame.
 *
 * What is allowed, and why:
 *   - scripts: the application's own files, and a script written into the page only when it carries the number that
 *     was made for this one answer (the "nonce"). A script put into the page by anybody else has no such number.
 *     Event handlers written into the markup (onclick="…") are not allowed at all.
 *     'unsafe-eval' is needed: Alpine, which Livewire uses for everything that moves on a screen, reads the
 *     expressions written in the markup at run time. Livewire has a build without it (config livewire.csp_safe);
 *     the screens use expressions that build cannot read yet. Dropping it is list line T-072.
 *   - styles: the application's own files and styles written into the page. Screens set widths and colours in
 *     the markup; a style cannot run code.
 *   - pictures: the application's own, pictures embedded in the page, and any https address: the catalogue's
 *     photographs still live on the present website (defect D-008).
 *   - everything else — fonts, requests, frames, workers — from the application itself only; no plug-ins; forms
 *     are sent to the application only; the page's base address cannot be changed.
 *   - one exception to "forms are sent to the application only": where the Google module is on, the two forms
 *     that start Google's sign-in are answered with a redirect to Google, and a browser holds the redirect that
 *     follows a form to the same rule. Google's sign-in address is then allowed, and no other.
 */
final class ContentPolicy
{
    /** Where App\Http\Controllers\Auth\GoogleOAuthController sends the browser. */
    public const GOOGLE_SIGN_IN = 'https://accounts.google.com';

    /** What the setting may say: enforce (the default), report (the browser only writes to its console), off. */
    public static function mode(): string
    {
        $mode = strtolower((string) config('woodex.security.csp', 'enforce'));

        return in_array($mode, ['enforce', 'report', 'off'], true) ? $mode : 'enforce';
    }

    public static function headerName(): ?string
    {
        return match (self::mode()) {
            'off' => null,
            'report' => 'Content-Security-Policy-Report-Only',
            default => 'Content-Security-Policy',
        };
    }

    /** The policy for one answer. $frameOptions is the value of X-Frame-Options this answer is sent with. */
    public static function for(string $nonce, string $frameOptions = 'SAMEORIGIN'): string
    {
        $self = "'self'";
        $policy = [
            'default-src' => [$self],
            'script-src' => [$self, "'nonce-{$nonce}'", "'unsafe-eval'"],
            'style-src' => [$self, "'unsafe-inline'"],
            'img-src' => [$self, 'data:', 'blob:', 'https:'],
            'font-src' => [$self, 'data:'],
            'connect-src' => [$self],
            'frame-src' => [$self, 'blob:'],
            'worker-src' => [$self, 'blob:'],
            'object-src' => ["'none'"],
            'base-uri' => [$self],
            'form-action' => [$self],
        ];

        // "Connect Google" (System) and "Link Google sign-in" (Profile) are forms; the answer sends the browser on
        // to Google. A browser refuses that step unless the policy names where it leads.
        if (Modules::enabled('google')) {
            $policy['form-action'][] = self::GOOGLE_SIGN_IN;
        }

        // Who may put this page in a frame follows the older header, so that the two never disagree.
        $ancestors = match (strtoupper($frameOptions)) {
            'SAMEORIGIN' => [$self],
            'DENY' => ["'none'"],
            default => null, // the header is switched off (an embedded preview): say nothing here either
        };

        if ($ancestors !== null) {
            $policy['frame-ancestors'] = $ancestors;
        }

        // A developer's machine: Vite serves scripts and styles from its own address while it watches the files.
        if (app()->environment('local') && Vite::isRunningHot()) {
            $hot = rtrim(trim((string) @file_get_contents(Vite::hotFile())), '/');

            if ($hot !== '') {
                $socket = (string) preg_replace('#^http#', 'ws', $hot);
                array_push($policy['script-src'], $hot);
                array_push($policy['style-src'], $hot);
                array_push($policy['connect-src'], $hot, $socket);
                array_push($policy['img-src'], $hot);
                array_push($policy['font-src'], $hot);
            }
        }

        return implode('; ', array_map(
            static fn (string $name, array $values): string => $name.' '.implode(' ', $values),
            array_keys($policy),
            $policy,
        ));
    }
}
