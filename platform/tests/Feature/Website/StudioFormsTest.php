<?php

declare(strict_types=1);

use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Lead;
use App\Support\AdminNavigation;

/*
| The Forms screen, ported from the v2.1 admin (docs/audit/16.8). The v1 pages post the same forms they
| always did; the OS stores every enquiry as a lead, so the screen is a read-only view of what arrived and
| of the alert routing that already sends it — there is no second, per-form store.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
});

function formLead(string $detail, LeadSource $source, array $fields = ['name' => 'Ayesha', 'phone' => '0322 4000768'], ?string $when = null): Lead
{
    // A lead has no name or phone of its own: the person is the contact, the answers are in meta.
    return Lead::factory()->create([
        'source' => $source,
        'source_detail' => $detail,
        'meta' => ['form' => $detail, 'fields' => $fields],
        'created_at' => $when === null ? now() : now()->parse($when),
    ]);
}

it('opens the forms screen inside the OS session', function () {
    $this->get(route('admin.website.forms'))
        ->assertOk()
        ->assertSee('Forms')
        ->assertSee('/assets/admin-v21/admin-forms.js', false);
});

it('keeps people without website.edit out', function () {
    $this->actingAs($this->userWithRole('sales'));

    $this->get(route('admin.website.forms'))->assertForbidden();
    $this->postJson(route('admin.website.studio.api'), ['action' => 'cms_forms'])->assertForbidden();
});

it('counts what each form received, and only that form', function () {
    formLead('brief', LeadSource::WebsiteForm);
    formLead('brief', LeadSource::WebsiteForm);
    formLead('contact', LeadSource::WebsiteForm);
    // An old one: counted in the total, not in the last 30 days.
    formLead('brief', LeadSource::WebsiteForm, ['name' => 'Old', 'phone' => '0322 4000768'], '-90 days');

    $forms = collect($this->postJson(route('admin.website.studio.api'), ['action' => 'cms_forms'])
        ->assertOk()
        ->json('forms'))
        ->keyBy('key');

    expect($forms['brief']['total'])->toBe(3)
        ->and($forms['brief']['month'])->toBe(2)
        ->and($forms['brief']['last'])->not->toBeNull()
        ->and($forms['contact']['total'])->toBe(1)
        ->and($forms['estimator']['total'])->toBe(0)
        ->and($forms['website']['total'])->toBe(0);
});

it('shows the field names the leads actually carry', function () {
    formLead('brief', LeadSource::WebsiteForm, ['name' => 'Ayesha', 'phone' => '0322 4000768', 'area' => '10 marla']);
    formLead('brief', LeadSource::WebsiteForm, ['name' => 'Sara', 'phone' => '0300 1112223', 'style' => 'Modern']);

    $forms = collect($this->postJson(route('admin.website.studio.api'), ['action' => 'cms_forms'])
        ->assertOk()
        ->json('forms'))
        ->keyBy('key');

    expect($forms['brief']['fields'])->toContain('name', 'phone', 'area', 'style')
        ->and($forms['brief']['fields'])->not->toContain('nothing');
});

it('reports the alert routing the OS actually uses', function () {
    $alerts = $this->postJson(route('admin.website.studio.api'), ['action' => 'cms_forms'])
        ->assertOk()
        ->json('alerts');

    expect($alerts)->toHaveKeys(['automation', 'roles', 'quiet', 'telegram'])
        ->and($alerts['roles'])->toBeArray();
});

it('lists Forms as a live destination in the sidebar', function () {
    $items = collect(AdminNavigation::for($this->owner))
        ->flatMap(fn (array $group): array => $group['items'])
        ->keyBy('label');

    expect($items['Forms']['route'])->toBe('admin.website.forms')
        ->and($items['Forms']['phase'])->toBeNull();
});
