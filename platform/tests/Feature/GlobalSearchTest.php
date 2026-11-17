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

it('offers only screens that open: nothing from a switched-off module, for any role', function (string $role) {
    // The palette lists the registry's screens plus its own shortcuts (tabs and sub-screens). The shortcuts were
    // checked for permission only, so "Canned replies" and "WhatsApp templates" were offered while their modules
    // were off, and opened a "not found" page.
    config(['modules.enabled' => 'lead_api']);
    $user = $this->userWithRole($role);
    $this->actingAs($user);

    $pages = Livewire::test(GlobalSearch::class)->viewData('pages');
    expect($pages)->not->toBeEmpty();

    $refused = [];
    foreach ($pages as $page) {
        $status = $this->followingRedirects()->get($page['url'])->getStatusCode();
        if ($status !== 200) {
            $refused[] = $page['label'].' → '.$status;
        }
    }
    expect($refused)->toBe([]);

    $urls = $pages->pluck('url')->all();
    expect($urls)->not->toContain(route('admin.canned-replies'))->not->toContain(route('admin.whatsapp.templates'));

    // Switched on, both are offered again to those who may use them.
    config(['modules.enabled' => '*']);
    $urls = Livewire::test(GlobalSearch::class)->viewData('pages')->pluck('url')->all();
    if ($user->can('settings.manage')) {
        expect($urls)->toContain(route('admin.canned-replies'));
    }
    if ($user->can('integrations.manage')) {
        expect($urls)->toContain(route('admin.whatsapp.templates'));
    }
})->with(['owner', 'sales_manager', 'sales', 'accountant', 'support']);
