<?php

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\System\Models\SavedView;
use App\Livewire\Admin\Crm\LeadsIndex;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

it('finds a lead by phone typed in local format', function () {
    $manager = $this->userWithRole('sales_manager');
    $contact = Contact::factory()->create(['name' => 'Bilal Raza', 'phone' => '+923001112233']);
    Lead::factory()->for($contact)->create();
    Lead::factory()->create();

    $this->actingAs($manager);

    Livewire::test(LeadsIndex::class)
        ->set('search', '0300-1112233')
        ->assertSee('Bilal Raza')
        ->assertViewHas('leads', fn ($leads) => $leads->total() === 1);
});

it('creates a lead from the quick-add form through CaptureLead', function () {
    $sales = $this->userWithRole('sales');
    $this->actingAs($sales);

    Livewire::test(LeadsIndex::class)
        ->set('showCreate', true)
        ->set('newName', 'Walk-in Client')
        ->set('newPhone', '0333 7654321')
        ->set('newSource', 'walk_in')
        ->call('createLead')
        ->assertHasNoErrors()
        ->assertSet('showCreate', false)
        ->assertDispatched('toast');

    $lead = Lead::query()->sole();

    expect($lead->source->value)->toBe('walk_in')
        ->and($lead->owner_id)->toBe($sales->id)
        ->and($lead->contact->phone)->toBe('+923337654321');
});

it('limits sales users to their own leads', function () {
    $sales = $this->userWithRole('sales');
    Lead::factory()->create(['owner_id' => $sales->id]);
    Lead::factory()->count(3)->create();

    $this->actingAs($sales);

    Livewire::test(LeadsIndex::class)->assertViewHas('leads', fn ($leads) => $leads->total() === 1);
});

it('sorts, pages and remembers a saved view as the default', function () {
    $manager = $this->userWithRole('sales_manager');
    $this->actingAs($manager);
    $cold = Lead::factory()->create(['score' => 10]);
    $hot = Lead::factory()->create(['score' => 95]);

    Livewire::test(LeadsIndex::class)
        ->set('sort', 'score')
        ->set('perPage', 50)
        ->assertViewHas('leads', fn ($leads) => $leads->first()->is($hot) && $leads->perPage() === 50)
        ->call('toggleColumn', 'score')
        ->set('viewName', 'Hot first')
        ->set('viewAsDefault', true)
        ->call('saveView')
        ->assertHasNoErrors();

    expect(SavedView::query()->sole()->filters)->toMatchArray(['sort' => 'score', 'perPage' => 50]);

    // Opening the screen again applies the default view.
    Livewire::test(LeadsIndex::class)
        ->assertSet('sort', 'score')
        ->assertSet('perPage', 50)
        ->assertSee('Score');
});

it('exports the filtered leads as CSV for people with the export permission', function () {
    $manager = $this->userWithRole('sales_manager');
    $this->actingAs($manager);
    $lead = Lead::factory()->create();
    $lead->contact->update(['name' => '=HYPERLINK("evil")']);

    $response = Livewire::test(LeadsIndex::class)->call('export');
    $response->assertFileDownloaded();

    $this->actingAs($this->userWithRole('sales'));
    Livewire::test(LeadsIndex::class)->call('export')->assertForbidden();
});

it('bulk assigns, moves and deletes selected leads within permissions', function () {
    $admin = $this->userWithRole('admin');
    $sales = $this->userWithRole('sales');
    $this->actingAs($admin);
    $leads = Lead::factory()->count(3)->create(['owner_id' => null]);
    $ids = $leads->pluck('id')->map(fn ($id) => (string) $id)->all();

    Livewire::test(LeadsIndex::class)
        ->set('selected', $ids)
        ->call('bulkAssign', (string) $sales->id)
        ->assertSet('selected', [])
        ->set('selected', $ids)
        ->call('bulkMove', 'contacted')
        ->set('selected', [$ids[0]])
        ->call('bulkDelete');

    expect(Lead::query()->whereIn('id', $ids)->pluck('owner_id')->unique()->all())->toBe([$sales->id])
        ->and(Lead::query()->whereIn('id', $ids)->with('stage')->get()->every(fn ($l) => $l->stage->key === 'contacted'))->toBeTrue()
        ->and(Lead::query()->count())->toBe(2)
        ->and(Lead::withTrashed()->count())->toBe(3);

    // Sales users cannot bulk delete.
    $this->actingAs($sales);
    Livewire::test(LeadsIndex::class)->set('selected', [$ids[1]])->call('bulkDelete')->assertForbidden();
});

it('lands the N shortcut: /leads?new=1 opens the composer straight away', function () {
    $sales = $this->userWithRole('sales');

    Livewire::actingAs($sales)
        ->withQueryParams(['new' => '1'])
        ->test(LeadsIndex::class)
        ->assertSet('showCreate', true);

});

it('opens the leads list quiet when no shortcut asks for the composer', function () {
    $sales = $this->userWithRole('sales');

    Livewire::actingAs($sales)
        ->test(LeadsIndex::class)
        ->assertSet('showCreate', false);
});

it('filters the list to one enquiry month', function () {
    $manager = $this->userWithRole('sales_manager');
    $this->actingAs($manager);

    $january = Lead::factory()->create();
    $january->forceFill(['created_at' => Carbon::parse('2025-01-15 12:00:00')])->save();

    $february = Lead::factory()->create();
    $february->forceFill(['created_at' => Carbon::parse('2025-02-15 12:00:00')])->save();

    Livewire::test(LeadsIndex::class)
        ->set('month', '2025-01')
        ->assertViewHas('leads', fn ($leads) => $leads->total() === 1 && $leads->first()->is($january));
});

it('filters by business line from the line tabs', function () {
    $manager = $this->userWithRole('sales_manager');
    $this->actingAs($manager);

    Lead::factory()->create(['business_line' => 'interior']);
    $furniture = Lead::factory()->create(['business_line' => 'furniture']);

    Livewire::test(LeadsIndex::class)
        ->assertSee('All lines')
        ->set('line', 'furniture')
        ->assertViewHas('leads', fn ($leads) => $leads->total() === 1 && $leads->first()->is($furniture));
});
