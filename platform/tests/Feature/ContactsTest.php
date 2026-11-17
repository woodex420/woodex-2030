<?php

use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Livewire\Admin\Crm\ContactShow;
use App\Livewire\Admin\Crm\ContactsIndex;
use Livewire\Livewire;

it('creates a contact with a normalised phone and opens it', function () {
    $sales = $this->userWithRole('sales');
    $this->actingAs($sales);

    Livewire::test(ContactsIndex::class)
        ->call('openCreate')
        ->set('form.name', 'Ayesha Khan')
        ->set('form.phone', '0321 4567890')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect();

    $contact = Contact::query()->sole();

    expect($contact->phone)->toBe('+923214567890')
        ->and($contact->owner_id)->toBe($sales->id);
});

it('refuses a duplicate phone in any format and points to the existing contact', function () {
    $this->actingAs($this->userWithRole('sales_manager'));
    $existing = Contact::factory()->create(['name' => 'Usman Tariq', 'phone' => '+923001234567']);

    Livewire::test(ContactsIndex::class)
        ->call('openCreate')
        ->set('form.name', 'Usman T.')
        ->set('form.phone', '+92 300 1234567')
        ->call('create')
        ->assertHasErrors(['form.phone'])
        ->assertSet('duplicateId', $existing->id);

    expect(Contact::query()->count())->toBe(1);
});

it('shows sales users only the contacts they own or have leads for', function () {
    $sales = $this->userWithRole('sales');
    $mine = Contact::factory()->create(['owner_id' => $sales->id]);
    $viaLead = Lead::factory()->create(['owner_id' => $sales->id])->contact;
    $other = Contact::factory()->create();

    $this->actingAs($sales);

    Livewire::test(ContactsIndex::class)
        ->assertViewHas('contacts', fn ($contacts) => $contacts->pluck('id')->sort()->values()->all() === collect([$mine->id, $viaLead->id])->sort()->values()->all());

    $this->get(route('admin.contacts.show', $other))->assertForbidden();
    $this->get(route('admin.contacts.show', $mine))->assertOk()->assertSee($mine->name);
});

it('merges a duplicate contact and moves its leads and timeline', function () {
    $this->actingAs($this->userWithRole('sales_manager'));

    $target = Contact::factory()->create(['name' => 'Hamza Ali', 'phone' => '+923001112233', 'email' => null]);
    $duplicate = Contact::factory()->create(['name' => 'Hamza', 'phone' => '+923331112233', 'email' => 'hamza@example.com']);
    $lead = Lead::factory()->for($duplicate)->create();
    Activity::create(['contact_id' => $duplicate->id, 'type' => 'note', 'body' => 'Old note', 'occurred_at' => now()]);

    Livewire::test(ContactShow::class, ['contact' => $target])
        ->call('merge', $duplicate->id)
        ->assertHasNoErrors();

    $target->refresh();

    expect($lead->fresh()->contact_id)->toBe($target->id)
        ->and(Activity::query()->where('body', 'Old note')->value('contact_id'))->toBe($target->id)
        ->and($target->email)->toBe('hamza@example.com')
        ->and($target->alt_phone)->toBe('+923331112233')
        ->and(Contact::query()->find($duplicate->id))->toBeNull();
});

it('does not let sales users merge contacts', function () {
    $sales = $this->userWithRole('sales');
    $target = Contact::factory()->create(['owner_id' => $sales->id]);
    $duplicate = Contact::factory()->create(['owner_id' => $sales->id]);

    $this->actingAs($sales);

    Livewire::test(ContactShow::class, ['contact' => $target])
        ->call('merge', $duplicate->id)
        ->assertForbidden();
});

it('opens a new enquiry for an existing client', function () {
    $sales = $this->userWithRole('sales');
    $contact = Contact::factory()->create(['owner_id' => $sales->id, 'is_client' => true, 'phone' => '+923004445566']);

    $this->actingAs($sales);

    Livewire::test(ContactShow::class, ['contact' => $contact])
        ->set('showLead', true)
        ->set('leadLine', 'furniture')
        ->set('leadService', 'furniture')
        ->call('createLead')
        ->assertHasNoErrors()
        ->assertRedirect();

    $lead = $contact->leads()->sole();

    expect($lead->source->value)->toBe('returning_client')
        ->and($lead->business_line->value)->toBe('furniture')
        ->and($lead->owner_id)->toBe($sales->id);
});
