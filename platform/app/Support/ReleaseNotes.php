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
                'date' => '2026-11-21',
                'title' => 'The dashboard: every number opens the records it counts',
                'notes' => [
                    'Dashboard: every number says how many records it counts and opens exactly those records. "Open the 12 enquiries" opens twelve enquiries, for the same dates and the same business line. The list says above its rows what it is cut to ("Filtered: Enquiries made 1 Nov – 20 Nov 2026"), and "Clear" gives the whole list back.',
                    'Dashboard, new for the roles that may open the lists behind them: the sales funnel with its conversion rate (quotations accepted out of quotations sent), money owed by how late it is, what we owe our vendors and when, the health of the running projects, and the milestones of the next 14 days. The inbox has its box where Conversations is switched on.',
                    'Dashboard: under each chart stands what it shows: its dates, whether the amounts are in PKR, and what is counted and what is left out. A box with nothing to count says so in a sentence instead of a row of noughts.',
                    'Dashboard: if one box cannot be worked out, that box says so, with a request number to pass on and "Try again". The rest of the page stays as it is. Before, one failing number took the whole page with it.',
                    'Dashboard: you see only the boxes of your role. A number whose list you may not open is shown without a link, and says why when you point at it.',
                    'Quotations, Invoices, Payments, Projects and Payables by age can be cut to a period and to what a dashboard number counts (sent, accepted, issued, received, late by 31 to 60 days, due within 7 days, behind schedule). A list that fits on one page says how many rows it has.',
                    'Leads: a month now runs from midnight to midnight in Pakistan. An enquiry of the last evening of October was listed under November, and one of the last evening of November under December.',
                    '"Today" on the dashboard and in the Leads list is today only: an enquiry dated tomorrow is no longer counted in it.',
                    'The number beside "Approvals" in the menu, and "Approvals waiting" in the morning message on Telegram, count the quotations that are there to be decided, as the Approvals screen lists them. They also counted a request whose quotation had been deleted.',
                    'The chart of new leads shows its days under it and whole numbers at its side again. Since 18 November it showed 1, 2, 3 … for the days and steps of 0.2 for the leads.',
                ],
            ],
            [
                'date' => '2026-11-20',
                'title' => 'Lists: sort by a press on a heading, and cards on a phone',
                'notes' => [
                    'On a phone, Leads, Quotations and Invoices are drawn as cards: nothing of the list scrolls sideways any more. A lead\'s card shows its reference, the contact with call and WhatsApp, the stage, the owner and the next action. A quotation\'s card shows its status, value and when it was last changed. An invoice\'s card shows the client, status, amount, balance and due date. Press the name on top of a card to open the record. On a wider screen the tables are as they were.',
                    'Leads on a phone: tick a card to choose it, or "Select all on this page" above the cards; "Assign to", "Move to" and "Delete" appear above. To sort on a phone, use the choice above the list.',
                    'Leads and Quotations: press the heading of a column to sort the list by it. On Leads a second press on "Lead" turns the list round, oldest first. An arrow in the heading shows which way the list is sorted. It works from the keyboard too: Tab to the heading, then Enter. The choice "Sort" above the list is still there.',
                    'Leads: "Newest first" and "Oldest first" now go by the date of the enquiry, the date you see under each reference. Leads brought in from a sheet with their own dates no longer stand on top as the newest.',
                    'Leads: the tick boxes are easier to hit with a mouse. The box looks the same; the space around it now counts.',
                    'Seven lists are drawn by one shared table now: Leads, Quotations, Invoices, Projects, Payables by age, Users and Receivables. On a wide screen they look as they did. A screen reader is told the name of each table, and which cell names a row.',
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
