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
                'date' => '2026-11-19',
                'title' => 'Products and their pages can be read from this admin by the shop, and the shop is not switched on to them yet',
                'notes' => [
                    'Products: a published product is now readable outside this admin — its name, description, categories, series, price, availability and photographs — and so is the list of published products, in pages, narrowed by category or series. Only the copy you published is ever handed out: a draft, a withdrawn product and an archived one are not, and neither is a photograph of a draft or of an older copy.',
                    'Prices are whole rupees and never a guess: a product published to ask about is handed out as "price on request" rather than as a figure, and a sale price is handed out only between the dates you set.',
                    'This changes nothing you see on the live shop yet. The shop reads these new addresses only when a switch is turned on for it, and that switch is off in this release — so the shop shows exactly what it shows today. It is left off on purpose: every one of your 170 real products is still a draft, so a shop reading the published products would show an empty catalogue.',
                    'One product was published on the throwaway demo workspace to prove the two ends fit together, and its database is thrown away at every start. No real product was published.',
                ],
            ],
            [
                'date' => '2026-11-19',
                'title' => 'Feature status tells you about every screen you can open, and finds one by name',
                'notes' => [
                    'Feature status (System): the page now tells a person about the screens their own role can open, without a gap. A few System screens are open to either of two permissions — Access & team, Connections, Platform — and the page used to know only the first, so it left the others out while the menu offered them.',
                    'Feature status: the four numbers at the top are filters now — press "Built, switched off" to see only those. A search box finds a destination by name, and says so when nothing matches.',
                    'Feature status: what changed in each release sits below the list of destinations and opens one release at a time, so the page opens on what you came for. The "Open…" links are a comfortable size to press on a phone.',
                ],
            ],
            [
                'date' => '2026-11-19',
                'title' => 'Two enquiries in the same moment are both saved, and an enquiry sent twice is one',
                'notes' => [
                    'Leads: when the enquiries of two new people are saved in the same moment (two visitors of the shop, or two of you adding a lead each), both are saved, one after the other. Before, the database could stop one of the two, and that enquiry was not saved.',
                    'The connection the shop will use: an enquiry or a basket that arrives twice (a visitor who presses twice, a connection that sends again) is written once. Before, the second one was added to the timeline as a "Returning enquiry" and the team was told again. The shop is not connected to this workspace yet, so you will see this once it is.',
                ],
            ],
            [
                'date' => '2026-11-18',
                'title' => 'Two people can work in the accounts at the same moment',
                'notes' => [
                    'Accounts and payments: when two people save in the same moment (two invoices issued, two bills paid, two payments recorded for one client), both are saved, one after the other. Before, the database could stop one of the two and show an error page.',
                    'When two people work on the same record in the same moment, the first one is saved and the second is told what happened, in words: the invoice is paid already, the bill cannot take another payment, the entry is reversed already. Nothing is written twice.',
                    'Everywhere else: if the database does stop a save because somebody else was saving the same records, the page now says so and asks you to do it again. It used to say that something went wrong on our side.',
                ],
            ],
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
