<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Livewire\Admin\Crm\ClientsIndex;
use App\Livewire\Admin\Crm\CustomersIndex;
use App\Livewire\Admin\Crm\VisitorsIndex;
use App\Settings\SalesSettings;
use Livewire\Livewire;

/*
| Slice J — the contact ladder the reference product shows as five pages: everyone, leads, clients, customers and
| "visitors". The OS already knew all five; what it lacked was the views. Each one here is a fact read from the
| records — is_client (set when a quotation is accepted), an accepted quote, a project — never a label typed by hand.
| "Visitors" is named to match the reference, and says plainly that the OS records enquiries, not page views.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->support = $this->userWithRole('support');

    // Pin grouping so the value assertion below is deterministic regardless of the seeded settings.
    app(SalesSettings::class)->fill(['lakh_grouping' => false])->save();
});

function ladderContact(string $name, array $attributes = []): Contact
{
    return Contact::factory()->create(array_merge(['name' => $name], $attributes));
}

function ladderLead(Contact $contact, string $source = 'website_form'): Lead
{
    return Lead::factory()->create([
        'contact_id' => $contact->id,
        'owner_id' => test()->owner->id,
        'business_line' => BusinessLine::Interior->value,
        'source' => $source,
    ]);
}

it('lists clients as the people the OS has actually won', function () {
    ladderContact('Won Client', ['is_client' => true]);
    ladderContact('Prospect Only');

    Livewire::actingAs($this->owner)
        ->test(ClientsIndex::class)
        ->assertSee('Won Client')
        ->assertDontSee('Prospect Only')
        ->assertSee('Clients');
});

it('counts a customer by accepted quotations and shows the value they bring', function () {
    $buyer = ladderContact('Bought Twice');
    $lead = ladderLead($buyer);

    $quote = app(CreateQuote::class)->handle($buyer, BusinessLine::Interior, $this->owner, $lead, null, 'Kitchen');
    $quote->forceFill(['status' => QuoteStatus::Accepted->value, 'total_minor' => 500_000 * 100])->save();

    $second = app(CreateQuote::class)->handle($buyer, BusinessLine::Interior, $this->owner, $lead, null, 'Lounge');
    $second->forceFill(['status' => QuoteStatus::Accepted->value, 'total_minor' => 250_000 * 100])->save();

    $talker = ladderContact('Only Talking');
    $theirLead = ladderLead($talker);
    app(CreateQuote::class)->handle($talker, BusinessLine::Interior, $this->owner, $theirLead, null, 'Draft only');

    Livewire::actingAs($this->owner)
        ->test(CustomersIndex::class)
        ->assertSee('Bought Twice')
        ->assertSee('Rs 750,000')
        ->assertDontSee('Only Talking');
});

it('calls a website enquiry a visitor until it converts, then drops it from the list', function () {
    $visitor = ladderContact('Web Visitor');
    $webLead = ladderLead($visitor, 'website_form');

    $converted = ladderContact('Converted Visitor', ['is_client' => true]);
    ladderLead($converted, 'estimator');

    $walkIn = ladderContact('Walk In');
    ladderLead($walkIn, 'walk_in');

    Livewire::actingAs($this->owner)
        ->test(VisitorsIndex::class)
        ->assertSee('Web Visitor')
        ->assertSee($webLead->source->label())
        ->assertDontSee('Converted Visitor')
        ->assertDontSee('Walk In');
});

it('answers the reference product URLs with the right tab', function () {
    $this->actingAs($this->owner)->get('/admin/clients')->assertRedirect('/admin/contacts?tab=clients');
    $this->actingAs($this->owner)->get('/admin/customers')->assertRedirect('/admin/contacts?tab=customers');
    $this->actingAs($this->owner)->get('/admin/visitors')->assertRedirect('/admin/contacts?tab=visitors');
});

it('keeps the ladder behind contacts.view and inside the viewer\'s scope', function () {
    $mine = ladderContact('Mine To See', ['owner_id' => $this->support->id, 'is_client' => true]);
    ladderContact('Someone Else\'s', ['is_client' => true]);

    $this->actingAs($this->owner)->get('/admin/contacts?tab=clients')->assertOk();
    $this->actingAs($this->owner)->get('/admin/contacts?tab=customers')->assertOk();
    $this->actingAs($this->owner)->get('/admin/contacts?tab=visitors')->assertOk();

    // Support may read contacts, but only the ones they own or share a lead with.
    Livewire::actingAs($this->support)
        ->test(ClientsIndex::class)
        ->assertSee('Mine To See')
        ->assertDontSee('Someone Else');

    // Editor has no contacts.view at all.
    $editor = $this->userWithRole('editor');
    $this->actingAs($editor)->get('/admin/contacts?tab=clients')->assertForbidden();
    $this->actingAs($editor)->get('/admin/clients')->assertForbidden();
});
