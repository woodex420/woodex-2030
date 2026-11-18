<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What changed for the people who work in the admin, release by release (master list T-056; document 07 §14:
 * "Feature availability and release notes"). Shown on the Feature status screen, newest first.
 *
 * Written for the person at the screen: what they can do now that they could not, in their words. What changed
 * under the surface is in the release reports of docs/woodex-os. A release that changes what a person sees adds
 * its entry here in the same pull request (AGENTS.md §6).
 */
final class ReleaseNotes
{
    /**
     * @return list<array{date: string, title: string, notes: list<string>}>
     */
    public static function all(): array
    {
        return [
            [
                'date' => '2026-11-18',
                'title' => 'Catalogue products in quotations, a question before typed work is lost, your own preferences',
                'notes' => [
                    'Quotations: a line can be chosen from the catalogue. It keeps the product\'s code, name and price as they were when it was chosen. A product without a price is added as "price on request" and must be priced before the quotation is sent.',
                    'Every screen: when you leave a form you have typed in and not saved, the admin asks first. "Stay" keeps what you typed. A figure typed into a quotation is stored even when you press a menu link straight away.',
                    'Workspace preferences (in the menu under your name, and under System): the appearance follows you to another browser, single-key shortcuts can be switched off, and you choose the screen that opens after sign-in.',
                    'Team onboarding (under System, for whoever manages the team): who has set a password, signed in, and switched on two-factor sign-in. The link to set a password can be sent again from there. A first sign-in opens a welcome with the first steps.',
                    'Notices: eight messages that reported a failure were shown in green as "Saved". They are shown as failures now.',
                ],
            ],
        ];
    }
}
