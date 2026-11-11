<?php

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Actions\DeleteQuote;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\CatalogItem;
use App\Domain\Sales\Models\Quote;
use App\Domain\Sales\Models\QuoteTemplate;
use App\Livewire\Admin\GlobalSearch;
use App\Livewire\Admin\Sales\QuoteCreate;
use App\Livewire\Admin\Sales\QuotesIndex;
use App\Livewire\Admin\Sales\QuoteTemplates;
use App\Livewire\Admin\Sales\RateBook;
use App\Livewire\Admin\System\RecycleBin;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->company = Company::create(['name' => 'Gulberg Developers', 'type' => 'developer']);
    $this->lead = Lead::factory()->create(['owner_id' => $this->owner->id, 'business_line' => 'interior']);
    $this->lead->contact->update(['company_id' => $this->company->id, 'name' => 'Ayesha Malik']);
    $this->template = QuoteTemplate::query()->where('slug', 'renovation')->firstOrFail();
    $this->quote = app(CreateQuote::class)->handle($this->lead->contact->refresh(), $this->template->business_line, $this->owner, $this->lead, $this->template);
});

it('lists quotations with status chips, search and filters', function () {
    $other = app(CreateQuote::class)->handle($this->lead->contact, $this->template->business_line, $this->owner, $this->lead, null, 'Kitchen only');
    $other->update(['status' => QuoteStatus::Sent, 'sent_at' => now()->subDays(5)]);

    $this->actingAs($this->owner)->get(route('admin.quotes.index'))
        ->assertOk()->assertSee('WI-10100')->assertSee('Ayesha Malik')->assertSee('Needs follow-up');

    Livewire::actingAs($this->owner)->test(QuotesIndex::class)
        ->set('status', 'draft')->assertSee('WI-10100')->assertDontSee('WI-10101')
        ->set('status', 'follow_up')->assertSee('WI-10101')->assertDontSee('WI-10100')
        ->set('status', '')->set('search', 'Kitchen')->assertSee('WI-10101')->assertDontSee('WI-10100')
        ->set('search', 'Gulberg Dev')->assertSee('WI-10100')->assertSee('WI-10101');
});

it('keeps quotations away from roles without access', function () {
    $this->actingAs($this->userWithRole('editor'))->get(route('admin.quotes.index'))->assertForbidden();
    $this->actingAs($this->userWithRole('accountant'))->get(route('admin.quotes.create'))->assertForbidden();

    $sales = $this->userWithRole('sales');
    Livewire::actingAs($sales)->test(QuotesIndex::class)->assertDontSee('WI-10100')->assertSee('No quotations yet');
});

it('starts a quotation from an enquiry with the client and line filled in', function () {
    $this->actingAs($this->owner)->get(route('admin.quotes.create', ['lead' => $this->lead->id]))
        ->assertOk()->assertSee('Ayesha Malik')->assertSee('Renovation');

    Livewire::actingAs($this->owner)->withQueryParams(['lead' => $this->lead->id])->test(QuoteCreate::class)
        ->assertSet('contactId', $this->lead->contact_id)
        ->call('create')
        ->assertHasErrors('templateId')
        ->call('chooseTemplate', QuoteTemplate::query()->where('slug', 'interior')->value('id'))
        ->set('title', 'Office interior')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.quotes.show', Quote::query()->where('number', 'WI-10101')->firstOrFail()));

    expect(Quote::query()->where('number', 'WI-10101')->first())
        ->lead_id->toBe($this->lead->id)
        ->title->toBe('Office interior');
});

it('finds a client by name and switches to the furniture series for a furniture template', function () {
    Livewire::actingAs($this->owner)->test(QuoteCreate::class)
        ->set('contactSearch', 'Ayesha')
        ->assertSee('Ayesha Malik')
        ->call('selectContact', $this->lead->contact_id)
        ->assertSet('leadId', $this->lead->id)
        ->call('chooseTemplate', QuoteTemplate::query()->where('slug', 'furniture')->value('id'))
        ->assertSet('line', 'furniture')
        ->call('create')
        ->assertHasNoErrors();

    expect(Quote::query()->where('number', 'WF-10050')->exists())->toBeTrue();
});

it('lets everyone look up rates but only managers change the rate book', function () {
    $item = CatalogItem::query()->where('rate_minor', '>', 0)->firstOrFail();
    $sales = $this->userWithRole('sales');

    $this->actingAs($sales)->get(route('admin.sales.documents', ['tab' => 'rate-book']))->assertOk()->assertSee($item->description);
    Livewire::actingAs($sales)->test(RateBook::class)->call('openCreate')->assertForbidden();

    $manager = $this->userWithRole('sales_manager');
    Livewire::actingAs($manager)->test(RateBook::class)
        ->call('openCreate')
        ->set('formCategory', 'Wood work')
        ->set('formDescription', 'Fluted MDF wall panel')
        ->set('formUnit', 'sft')
        ->set('formRate', '1,850')
        ->set('formCost', 'abc')
        ->call('save')
        ->assertHasErrors('formCost')
        ->set('formCost', '1,100')
        ->call('save')
        ->assertHasNoErrors();

    expect(CatalogItem::query()->where('description', 'Fluted MDF wall panel')->first())
        ->rate_minor->toBe(185_000)
        ->internal_cost_minor->toBe(110_000);

    Livewire::actingAs($manager)->test(RateBook::class)->call('toggleActive', $item->id);
    expect($item->refresh()->is_active)->toBeFalse();
});

it('shows templates, their lines and lets managers edit the details', function () {
    $sales = $this->userWithRole('sales');

    Livewire::actingAs($sales)->test(QuoteTemplates::class)
        ->assertSee('Renovation')->assertSee('Turnkey Solution')
        ->call('preview', $this->template->id)
        ->assertSee($this->template->blueprint['sections'][0]['items'][0]['description'])
        ->call('openEdit', $this->template->id)
        ->assertForbidden();

    Livewire::actingAs($this->userWithRole('sales_manager'))->test(QuoteTemplates::class)
        ->call('openEdit', $this->template->id)
        ->set('formDescription', 'Full renovation, ten sections.')
        ->set('formScope', "- Demolition\n- Civil work")
        ->call('save')
        ->assertHasNoErrors();

    expect($this->template->refresh())
        ->description->toBe('Full renovation, ten sections.')
        ->scope_html->toBe('<ul><li>Demolition</li><li>Civil work</li></ul>');
});

it('lists quotations on the lead, contact and company pages', function () {
    $this->actingAs($this->owner);

    $this->get(route('admin.leads.show', $this->lead))->assertOk()
        ->assertSee('Quotations')->assertSee('WI-10100')
        ->assertSee(route('admin.quotes.create', ['lead' => $this->lead->id]), false);
    $this->get(route('admin.contacts.show', $this->lead->contact))->assertOk()->assertSee('WI-10100');
    $this->get(route('admin.companies.show', $this->company))->assertOk()->assertSee('WI-10100');
});

it('finds quotations in global search and restores deleted drafts', function () {
    Livewire::actingAs($this->owner)->test(GlobalSearch::class)->set('term', 'WI-101')->assertSee('WI-10100');

    app(DeleteQuote::class)->handle($this->quote, $this->owner);

    Livewire::actingAs($this->owner)->test(RecycleBin::class)
        ->set('tab', 'quotes')
        ->assertSee('WI-10100')
        ->call('restore', 'quote', $this->quote->id);

    expect(Quote::query()->find($this->quote->id))->not->toBeNull();
});
