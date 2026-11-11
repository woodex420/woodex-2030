<?php

use App\Domain\Conversations\Models\CannedReply;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\CreateQuote;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Livewire\Admin\Conversations\CannedRepliesIndex;
use App\Livewire\Admin\Conversations\Inbox;
use App\Settings\BusinessSettings;
use Database\Seeders\CannedRepliesSeeder;
use Livewire\Livewire;

/*
| CNV-004: canned replies. Type "/" in the inbox composer, pick one, it lands filled in for the client —
| the same four answers, typed once by the studio, personalised every time.
*/

it('seeds the everyday replies in English and Roman Urdu', function () {
    $this->seed(CannedRepliesSeeder::class);

    $titles = CannedReply::query()->pluck('title')->all();

    expect($titles)->toContain('Welcome')
        ->and($titles)->toContain('Studio hours')
        ->and($titles)->toContain('Quotation link')
        ->and($titles)->toContain('Khush amdeed')
        ->and(CannedReply::query()->where('locale', 'ur')->count())->toBeGreaterThanOrEqual(2)
        ->and(CannedReply::query()->count())->toBe(7);
});

it('matches what was typed after the slash, across title, keywords and body', function () {
    $this->seed(CannedRepliesSeeder::class);

    expect(CannedReply::matchesFor('hours'))->toContainOnlyInstancesOf(CannedReply::class)
        ->and(collect(CannedReply::matchesFor('hours'))->pluck('title')->all())->toContain('Studio hours')
        ->and(collect(CannedReply::matchesFor('timing'))->pluck('title')->all())->toContain('Studio hours') // keyword
        ->and(collect(CannedReply::matchesFor(''))->count())->toBe(6) // bare "/" lists the picker's first six
        ->and(collect(CannedReply::matchesFor('zzz'))->isEmpty())->toBeTrue();
});

it('fills the variables for the client on the other end, including the quote link', function () {
    $this->seed(CannedRepliesSeeder::class);

    $sales = $this->userWithRole('sales');
    $contact = Contact::factory()->create(['name' => 'Areeba Nadeem']);
    $lead = Lead::factory()->create(['contact_id' => $contact->id, 'owner_id' => $sales->id]);
    $quote = app(CreateQuote::class)->handle($contact, BusinessLine::Interior, $sales, $lead, null, 'Lounge');
    $quote->forceFill(['status' => QuoteStatus::Sent, 'sent_at' => now()->subDay()])->save();

    $conversation = Conversation::factory()->create(['contact_id' => $contact->id]);
    $business = app(BusinessSettings::class);

    $reply = CannedReply::query()->where('title', 'Quotation link')->first();
    $rendered = $reply->renderFor($conversation, $business);

    expect($rendered)->toStartWith('Areeba, here is your quotation:')
        ->and($rendered)->toContain($quote->public_id)
        ->and($rendered)->not()->toContain('{first_name}')
        ->and($rendered)->not()->toContain('{quote_link}');

    $hours = CannedReply::query()->where('title', 'Studio hours')->first();
    $renderedHours = $hours->renderFor($conversation, $business);

    expect($renderedHours)->toContain($business->hours()->opensAt)
        ->and($renderedHours)->toContain($business->hours()->closesAt)
        ->and($renderedHours)->toContain('Mon–Sat')
        ->and($renderedHours)->not()->toContain('{');
});

it('renders {first_name} safely when the contact has no single-word name and no quote exists', function () {
    $this->seed(CannedRepliesSeeder::class);

    $contact = Contact::factory()->create(['name' => '']);
    $conversation = Conversation::factory()->create(['contact_id' => $contact->id]);

    $reply = CannedReply::query()->where('title', 'Quotation link')->first();
    $rendered = $reply->renderFor($conversation, app(BusinessSettings::class));

    expect($rendered)->not()->toContain('{first_name}')
        ->and($rendered)->not()->toContain('{quote_link}');
});

it('offers the picker while typing / and fills the composer on pick, counting usage', function () {
    $this->seed(CannedRepliesSeeder::class);

    $owner = $this->userWithRole('owner');
    $contact = Contact::factory()->create(['name' => 'Areeba Nadeem']);
    $conversation = Conversation::factory()->create(['contact_id' => $contact->id]);

    $component = Livewire::actingAs($owner)->test(Inbox::class);
    $component->call('select', $conversation->id);

    // Typing "/" with a term narrows the picker to the match.
    $component->set('draft', '/hours')
        ->assertSee('Studio hours');

    $reply = CannedReply::query()->where('title', 'Studio hours')->first();
    $usageBefore = $reply->usage_count;

    $component->call('useCanned', $reply->id)
        ->assertSet('draft', fn (string $draft): bool => ! str_contains($draft, '{'))
        ->assertDontSee('Saved replies — click one');

    expect($reply->refresh()->usage_count)->toBe($usageBefore + 1);
});

it('manages the replies behind settings.manage, and keeps support out', function () {
    $owner = $this->userWithRole('owner');
    $support = $this->userWithRole('support');

    $this->actingAs($support)->get('/admin/settings/canned-replies')->assertForbidden();
    $this->actingAs($owner)->get('/admin/settings/canned-replies')->assertOk();

    Livewire::actingAs($owner)
        ->test(CannedRepliesIndex::class)
        ->call('openEditor')
        ->set('title', 'Thank you')
        ->set('body', 'Thank you {first_name}, it was a pleasure working with you.')
        ->set('locale', 'en')
        ->call('save')
        ->assertDontSee('Add saved reply');

    $reply = CannedReply::query()->where('title', 'Thank you')->first();

    expect($reply)->not->toBeNull();

    // Hiding takes it out of the picker.
    Livewire::actingAs($owner)->test(CannedRepliesIndex::class)->call('toggle', $reply->id);
    expect($reply->refresh()->is_active)->toBeFalse()
        ->and(collect(CannedReply::matchesFor('pleasure working'))->isEmpty())->toBeTrue();
});
