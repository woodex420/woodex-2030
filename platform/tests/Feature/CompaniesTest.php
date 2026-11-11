<?php

use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Livewire\Admin\Crm\CompaniesIndex;
use App\Livewire\Admin\Crm\CompanyShow;
use Livewire\Livewire;

beforeEach(function () {
    $this->manager = $this->userWithRole('sales_manager');
    $this->actingAs($this->manager);
});

it('creates a company with a normalised phone and opens its page', function () {
    $this->get(route('admin.contacts.index', ['tab' => 'companies']))->assertOk();

    Livewire::test(CompaniesIndex::class)
        ->call('openCreate')
        ->set('form.name', 'Bahria Developers')
        ->set('form.type', 'developer')
        ->set('form.phone', '0322 4000768')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect();

    $company = Company::query()->sole();
    expect($company->phone)->toBe('+923224000768')
        ->and($company->owner_id)->toBe($this->manager->id);

    $this->get(route('admin.companies.show', $company))->assertOk()->assertSee('Bahria Developers');
});

it('validates the company form', function () {
    Livewire::test(CompaniesIndex::class)
        ->call('openCreate')
        ->set('form.name', '')
        ->set('form.phone', '12')
        ->call('create')
        ->assertHasErrors(['form.name', 'form.phone']);
});

it('searches companies by name and phone', function () {
    Company::create(['name' => 'Gulberg Heights', 'type' => 'developer', 'phone' => '+924235780000']);
    Company::create(['name' => 'Studio Arch', 'type' => 'architect']);

    Livewire::test(CompaniesIndex::class)
        ->set('search', '042 3578 0000')
        ->assertSee('Gulberg Heights')
        ->assertDontSee('Studio Arch');
});

it('links people, shows their enquiries and soft deletes the company', function () {
    $company = Company::create(['name' => 'Packages Mall', 'type' => 'company']);
    $lead = Lead::factory()->create(['owner_id' => $this->manager->id]);

    Livewire::test(CompanyShow::class, ['company' => $company])
        ->set('contactSearch', mb_substr($lead->contact->name, 0, 4))
        ->call('linkContact', $lead->contact_id)
        ->assertSee($lead->contact->name)
        ->assertSee($lead->ref)
        ->call('delete')
        ->assertForbidden();

    // Deleting needs leads.delete (owner/admin).
    $this->actingAs($this->userWithRole('admin'));
    Livewire::test(CompanyShow::class, ['company' => $company])
        ->call('delete')
        ->assertRedirect(route('admin.contacts.index', ['tab' => 'companies']));

    expect($lead->contact->fresh()->company_id)->toBe($company->id)
        ->and($lead->fresh()->company_id)->toBe($company->id)
        ->and(Company::withTrashed()->find($company->id)->trashed())->toBeTrue();
});

it('lets sales users see companies but not delete them', function () {
    $company = Company::create(['name' => 'Emporium', 'type' => 'company']);
    $this->actingAs($this->userWithRole('sales'));

    Livewire::test(CompanyShow::class, ['company' => $company])
        ->assertOk()
        ->call('delete')
        ->assertForbidden();

    expect(Contact::query()->count())->toBe(0);
});
