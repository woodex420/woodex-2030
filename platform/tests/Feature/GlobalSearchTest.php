<?php

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Livewire\Admin\GlobalSearch;
use Livewire\Livewire;

it('finds leads and contacts by phone in any format and by reference', function () {
    $this->actingAs($this->userWithRole('sales_manager'));
    $contact = Contact::factory()->create(['name' => 'Sana Javed', 'phone' => '+923224000999']);
    $lead = Lead::factory()->for($contact)->create(['ref' => 'L-2026-000777']);
    Contact::factory()->create(['name' => 'Someone Else', 'phone' => '+923111111111']);

    Livewire::test(GlobalSearch::class)
        ->set('term', '0322-4000999')
        ->assertSee('Sana Javed')
        ->assertDontSee('Someone Else')
        ->assertViewHas('leads', fn ($leads) => $leads->modelKeys() === [$lead->id]);

    Livewire::test(GlobalSearch::class)
        ->set('term', '000777')
        ->assertViewHas('leads', fn ($leads) => $leads->modelKeys() === [$lead->id]);
});

it('only returns what the user may see', function () {
    $sales = $this->userWithRole('sales');
    $mine = Lead::factory()->create(['owner_id' => $sales->id]);
    $mine->contact->update(['name' => 'Kamran Mine']);
    $theirs = Lead::factory()->create();
    $theirs->contact->update(['name' => 'Kamran Theirs']);

    $this->actingAs($sales);

    Livewire::test(GlobalSearch::class)
        ->set('term', 'Kamran')
        ->assertSee('Kamran Mine')
        ->assertDontSee('Kamran Theirs');
});

it('shows quick links before typing', function () {
    $this->actingAs($this->userWithRole('sales'));

    Livewire::test(GlobalSearch::class)
        ->assertSee('My Day')
        ->assertSee('Pipeline')
        ->assertViewHas('searched', false);
});

it('is available in the admin header', function () {
    $this->actingAs($this->userWithRole('sales'))
        ->get(route('admin.leads.index'))
        ->assertSeeLivewire(GlobalSearch::class);
});
