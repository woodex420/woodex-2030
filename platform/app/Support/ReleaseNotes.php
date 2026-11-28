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
                'date' => '2026-11-28',
                'title' => 'Enquiries: a quotation basket from the shop names the products you published',
                'notes' => [
                    'Leads, the page of an enquiry, "Storefront basket requests": a basket that a visitor of the shop sent for products published here shows each product with its code, how many were asked for, and its price as it was published in that moment: "Executive Desk [ET-10010] · 2 each × Rs 42,500 = Rs 85,000". Changing or withdrawing the product afterwards changes nothing on a basket that was already sent.',
                    'A product published as "Price on request" stands on its line as "price on request", without a figure. The estimate under the lines adds up only the lines that have a price and says how many it leaves out. Where no line has a price it says "No catalogue estimate". It never prints "Rs 0".',
                    'A basket is refused, and nothing of it is saved, when it names a product that is a draft, was withdrawn, is archived or is marked unavailable, or an option the product no longer has. The visitor is told which line it is, and why.',
                    'A shop that shows the products of this admin offers no cart for them. The page of such a product offers "Add to Quote" and "Ask on WhatsApp" and says that it is ordered by quotation. Orders in this admin are not built yet.',
                    'None of this is switched on for a live shop yet. It starts on the day the shop is connected to this admin.',
                ],
            ],
            [
                'date' => '2026-11-27',
                'title' => 'Products: price on request, one code for one product, and what you publish is what the shop shows',
                'notes' => [
                    'Products, Pricing & stock: a new box, "Price on request". Tick it to publish a product that has no price. The shop then prints no figure, says "Price on request" and takes a quotation request. Until now a product without a price could not be published at all.',
                    'A product needs a price or that tick before it can be published, and never both: typing a price takes the tick off, and the tick beside a price is refused with the reason. No price is made up anywhere.',
                    'Products, "Product code and options" (it was called "Structured variants"): a product sold in one form has one row with its code (SKU) and nothing else. A product sold in several colours, materials or sizes has a row for each. A row without a price is sold at the base price: the price no longer has to be typed a second time, which every product of the catalogue would have needed.',
                    'One code names one product. A code that another product already has is refused when the draft is saved, whatever its capitals, and the screen names the product that has it.',
                    'Availability: "Confirm on request" is now called "Made to order (confirmed on request)". It says whether a product can be ordered and nothing about its price: a product made to order keeps its price on the shop.',
                    'Publishing: a shop that reads its products from this admin shows a product from the moment you publish it, with its photographs, code, description, specifications, delivery and warranty texts, and stops showing it the moment you withdraw it. A draft you save afterwards is seen by nobody until you publish again. The screen says so where it used to say that publishing changed nothing.',
                    'The list of products and the preview say "Price on request" for such a product. They said "Price not set".',
                    'The list of products and the summary beside the product form say in words how a product is available ("Made to order", not "on_request"), and write the price with its thousands.',
                ],
            ],
            [
                'date' => '2026-11-21',
                'title' => 'The screens that ship switched off speak for a furniture company',
                'notes' => [
                    'Chat Bot: the built-in instructions of the assistant name the business as System → Settings names it, and say that it designs and makes office furniture. They named another company and described another trade. Your own instructions, where you wrote any, are used as before.',
                    'Website chat: after the number, a visitor is asked what furniture they are looking for (office chairs, desks, workstations, a complete office fit-out). They were asked about a wardrobe, a home interior or a kitchen.',
                    'Marketing: a caption is drafted for a company that makes office furniture. The examples in the fields are furniture ones.',
                    'Conversations: the time of a WhatsApp message is kept as the moment it was sent. It would have been kept five hours early, and the 24 hours in which a customer may be answered freely would have ended after 19.',
                    'With Conversations switched on, the chat button at the top asks the server for new messages only while the chat bubble on the website is switched on (Website settings). It asked every ten seconds from every screen.',
                    'Estimations, Rates: where no rate list exists the tab says so in two sentences. It showed a red error twice, with the name of a folder on the server. New estimate: every field has its name, and the field "Service" says why it is empty and that no estimate can be saved until a service has a rate.',
                    'Saved replies: the two variables for the name and the opening hours are now called {business_name} and {business_hours}. A reply saved under the old names is filled as before.',
                    'Website: each Website screen says in one line what these screens manage: pages that the admin serves itself, not the shop. Blog, Portfolio, Forms and the Page builder show the demo notice like every other screen, and a new article is published under the name from System → Settings.',
                    'Website, Redirects: choosing a page from the list "…or pick a published page" now fills the form. The form kept what had been typed before.',
                    'Website: where this admin serves no page yet, Pages, SEO and SEO agent say so. They said that no page matched a search nobody had typed. The search fields the Page builder, Blog and Portfolio draw have a name a screen reader can say.',
                    'Words: the business is called Woodex, or "we", everywhere. Several screens, the statement of account a client receives and the chat window on the website used a word that belongs to another trade: Payables by age, Due payments, Automations, Sequences and Speed among them.',
                ],
            ],
            [
                'date' => '2026-11-21',
                'title' => 'Website Studio, where it is switched on: two links and the page builder',
                'notes' => [
                    'Website, Forms: the two links to "Automations" open Automations. They opened "This page does not exist".',
                    'Website, Page builder: where the site has no pages yet, the builder says so. It showed a red error. On a phone it says that it needs a wider screen; it was drawn wider than the window.',
                ],
            ],
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
