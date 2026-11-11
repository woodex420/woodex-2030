<?php

use App\Domain\Conversations\Models\Conversation;
use App\Domain\Crm\Actions\ExportContactData;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Livewire\Admin\Crm\ContactShow;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;

/*
| P9 (SYS-013): privacy tools — one person's data in one JSON, erase-on-request
| that keeps the books auditable, and retention that runs on a schedule instead
| of someone's memory.
*/

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
});

it('exports everything Woodex knows about one person as one JSON download', function () {
    $contact = Contact::factory()->create(['name' => 'Bilal Ahmed', 'phone' => '+923331234567', 'city' => 'Lahore']);
    $lead = Lead::factory()->for($contact)->create(['message' => 'Need a wardrobe for the master bedroom.']);
    Activity::create(['contact_id' => $contact->id, 'type' => 'note', 'body' => 'Called, will visit Friday.', 'occurred_at' => now()]);
    $conversation = Conversation::factory()->for($contact)->create();
    $conversation->messages()->create([
        'direction' => 'in',
        'type' => 'text',
        'body' => 'Assalam o Alaikum, do you make kitchen cabinets?',
    ]);

    $response = $this->actingAs($this->owner)->get(route('admin.contacts.data', $contact));

    $response->assertOk()->assertHeader('Content-Type', 'application/json; charset=utf-8');

    $payload = $response->json();
    expect($payload['contact']['name'])->toBe('Bilal Ahmed')
        ->and($payload['contact']['phone'])->toBe('+923331234567')
        ->and($payload['leads'][0]['message'])->toBe('Need a wardrobe for the master bedroom.')
        ->and($payload['activities'][0]['body'])->toBe('Called, will visit Friday.')
        ->and($payload['conversations'][0]['messages'][0]['body'])->toBe('Assalam o Alaikum, do you make kitchen cabinets?');
});

it('keeps the export behind the leads permission', function () {
    $contact = Contact::factory()->create();
    $editor = $this->userWithRole('editor'); // website work, not people data

    $this->actingAs($editor)->get(route('admin.contacts.data', $contact))->assertForbidden();
});

it('anonymises a person on request: the row stays for the books, the person leaves it', function () {
    $contact = Contact::factory()->create([
        'name' => 'Sana Raza', 'phone' => '+923007654321', 'email' => 'sana@example.com',
        'address' => 'House 12, Model Town', 'notes' => 'Prefers evening calls',
    ]);
    $lead = Lead::factory()->for($contact)->create(['message' => 'Renovation for a 1-kanal house.']);
    $conversation = Conversation::factory()->for($contact)->create();
    $message = $conversation->messages()->create(['direction' => 'in', 'type' => 'text', 'body' => 'My budget is flexible.']);

    Livewire::actingAs($this->owner)
        ->test(ContactShow::class, ['contact' => $contact])
        ->call('anonymiseContact')
        ->assertDispatched('toast');

    $fresh = $contact->fresh();
    expect($fresh->name)->toBe('Anonymised contact')
        ->and($fresh->phone)->toBeNull()
        ->and($fresh->email)->toBeNull()
        ->and($fresh->address)->toBeNull()
        ->and($fresh->notes)->toBeNull()
        ->and($fresh->anonymised_at)->not->toBeNull()
        ->and($fresh->leads()->first()->message)->toBe('[removed]')
        ->and($message->fresh()->body)->toBe('[removed]');

    // The re-export shows the same shape with the story gone.
    $payload = app(ExportContactData::class)->export($fresh);
    expect($payload['contact']['name'])->toBe('Anonymised contact')
        ->and($payload['contact']['phone'])->toBeNull();
});

it('refuses anonymisation without the delete permission', function () {
    $sales = $this->userWithRole('sales'); // works the leads, cannot erase people
    $contact = Contact::factory()->create(['owner_id' => $sales->id]); // their own contact — still not theirs to erase

    $this->actingAs($sales);

    Livewire::test(ContactShow::class, ['contact' => $contact])
        ->call('anonymiseContact')
        ->assertForbidden();
});

it('applies retention: stale lost leads go, quoted and recent ones stay; old non-lead transcripts go', function () {
    $lostStage = PipelineStage::byKey('lost');
    $newStage = PipelineStage::byKey('new');

    // Stale lost lead, no quotation — should go.
    $stale = Lead::factory()->create(['stage_id' => $lostStage->id, 'updated_at' => now()->subDays(40)]);

    // Lost but quoted — the books need it, it stays.
    $quoted = Lead::factory()->create(['stage_id' => $lostStage->id, 'updated_at' => now()->subDays(40)]);
    $quoted->quotes()->create([
        'number' => 'QT-RET-1', 'sequence_key' => 'retention', 'contact_id' => $quoted->contact_id,
        'business_line' => 'interior', 'title' => 'Retention guard', 'status' => 'sent',
        'currency' => 'PKR', 'subtotal_minor' => 0,
    ]);

    // Lost but recent — grace period, it stays.
    $fresh = Lead::factory()->create(['stage_id' => $lostStage->id, 'updated_at' => now()->subDays(5)]);

    // Not lost at all — stays.
    $open = Lead::factory()->create(['stage_id' => $newStage->id, 'updated_at' => now()->subDays(90)]);

    // Old transcript that never became a lead — goes, messages and all.
    $oldChat = Conversation::factory()->create(['lead_id' => null, 'last_message_at' => now()->subMonths(13)]);
    $oldChat->messages()->create(['direction' => 'in', 'type' => 'text', 'body' => 'Do you do sofas?']);
    $oldChat->messages()->create(['direction' => 'out', 'type' => 'text', 'body' => 'Yes we do.']);

    // Recent transcript and a lead-linked transcript — both stay.
    $recentChat = Conversation::factory()->create(['lead_id' => null, 'last_message_at' => now()->subDays(3)]);
    $leadChat = Conversation::factory()->create(['lead_id' => $open->id, 'last_message_at' => now()->subMonths(14)]);

    Artisan::call('woodex:privacy-retention');

    expect(Lead::query()->pluck('id'))->toContain($quoted->id, $fresh->id, $open->id)
        ->and(Lead::query()->pluck('id'))->not->toContain($stale->id)
        ->and(Conversation::query()->pluck('id'))->toContain($recentChat->id, $leadChat->id)
        ->and(Conversation::query()->pluck('id'))->not->toContain($oldChat->id)
        ->and($oldChat->messages()->count())->toBe(0);
});
