<?php

declare(strict_types=1);

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Livewire\Admin\Conversations\Inbox;
use App\Livewire\Admin\Conversations\LiveChatBell;
use App\Livewire\Admin\Crm\LeadShow;
use App\Livewire\Admin\Crm\LeadsIndex;
use App\Livewire\Admin\NotificationBell;
use App\Livewire\Admin\NotificationsPage;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
    Mail::fake();
    Notification::fake();
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
});

function communication47Alert(User $user, string $title = 'Site visit reminder', string $url = '/admin/leads'): DatabaseNotification
{
    return $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'isolated-qa',
        'data' => ['title' => $title, 'body' => 'Review the next action', 'url' => $url]]);
}

it('archives and restores own updates without schema changes or deletion on both surfaces', function (string $component) {
    $alert = communication47Alert($this->owner);
    Livewire::test($component)->assertSee('Site visit reminder')->call('archive', $alert->id)
        ->assertDontSee('Site visit reminder')->set('filter', 'archived')->assertSee('Site visit reminder')
        ->call('restore', $alert->id)->assertDontSee('Site visit reminder')->set('filter', 'all')->assertSee('Site visit reminder');
    expect($alert->refresh()->read_at)->not->toBeNull()->and($alert->data['url'])->toBe('/admin/leads')
        ->and(DatabaseNotification::count())->toBe(1)->and($alert->data)->not->toHaveKey('woodex_archived_at');
})->with([NotificationBell::class, NotificationsPage::class]);

it('keeps other users notifications private and rejects cross-user mutations', function (string $action) {
    $other = $this->userWithRole('sales');
    $alert = communication47Alert($other, 'Private teammate update');
    Livewire::test(NotificationsPage::class)->assertDontSee('Private teammate update')->call($action, $alert->id)->assertForbidden();
    expect($alert->refresh()->read_at)->toBeNull()->and($alert->data)->not->toHaveKey('woodex_archived_at');
})->with(['open', 'archive', 'restore']);

it('searches and filters actual own notification rows', function () {
    $one = communication47Alert($this->owner, 'Kitchen follow-up');
    communication47Alert($this->owner, 'Wardrobe visit')->markAsRead();
    Livewire::test(NotificationsPage::class)->set('search', 'Kitchen')->assertSee('Kitchen follow-up')->assertDontSee('Wardrobe visit')
        ->set('search', '')->set('filter', 'unread')->assertSee('Kitchen follow-up')->assertDontSee('Wardrobe visit')
        ->call('markAllRead')->assertDontSee('Kitchen follow-up');
    expect($one->refresh()->read_at)->not->toBeNull();
});

it('never redirects notification readers to unsafe destinations', function (string $url) {
    $alert = communication47Alert($this->owner, url: $url);
    Livewire::test(NotificationBell::class)->call('open', $alert->id)->assertNoRedirect();
    expect($alert->refresh()->read_at)->not->toBeNull();
})->with(['https://outside.example.test/steal', '//outside.example.test', 'javascript:alert(1)', '/%2foutside.example.test', '/%5coutside.example.test', "/admin/leads\r\nLocation:bad"]);

it('opens same-origin notifications using a root-relative native destination', function () {
    $alert = communication47Alert($this->owner, url: url('/admin/leads').'?due=overdue');
    Livewire::test(NotificationsPage::class)->call('open', $alert->id)->assertRedirect('/admin/leads?due=overdue');
});

it('makes Unread an exclusive real filter and clears it on other tabs', function () {
    $read = Conversation::factory()->create(['channel' => Channel::WebChat, 'unread_count' => 0, 'status' => 'open']);
    $unread = Conversation::factory()->create(['channel' => Channel::WebChat, 'unread_count' => 2, 'status' => 'pending']);
    Livewire::test(Inbox::class)->call('filterStatus', 'unread')->assertSet('unreadOnly', true)->assertSet('status', 'all')
        ->assertViewHas('conversations', fn ($rows) => $rows->pluck('id')->all() === [$unread->id])
        ->call('filterStatus', 'open')->assertSet('unreadOnly', false)
        ->assertViewHas('conversations', fn ($rows) => $rows->pluck('id')->all() === [$read->id]);
});

it('combines channel assignment and search with matching status counts', function () {
    $matching = Conversation::factory()->create(['channel' => Channel::WebChat, 'assignee_id' => $this->owner->id, 'last_preview' => 'QA kitchen enquiry', 'status' => 'open']);
    Conversation::factory()->create(['channel' => Channel::Whatsapp, 'assignee_id' => $this->owner->id, 'last_preview' => 'QA kitchen enquiry', 'status' => 'open']);
    Conversation::factory()->create(['channel' => Channel::WebChat, 'assignee_id' => null, 'last_preview' => 'QA kitchen enquiry', 'status' => 'open']);
    Livewire::test(Inbox::class)->set('channel', 'web_chat')->set('assignment', 'me')->set('search', 'QA kitchen')
        ->assertViewHas('conversations', fn ($rows) => $rows->pluck('id')->all() === [$matching->id])
        ->assertViewHas('counts', fn ($counts) => $counts['open'] === 1);
});

it('rejects inactive or nonexistent assignees without changing ownership', function (bool $inactive) {
    $conversation = Conversation::factory()->create(['assignee_id' => null]);
    $id = $inactive ? User::factory()->create(['is_active' => false])->id : 999999;
    Livewire::test(Inbox::class)->call('select', $conversation->id)->call('assign', $id)->assertHasErrors('assignee');
    expect($conversation->refresh()->assignee_id)->toBeNull();
})->with([true, false]);

it('shows the latest bounded thread and loads earlier messages on both chat surfaces', function (string $component) {
    $conversation = Conversation::factory()->create(['channel' => Channel::WebChat]);
    for ($i = 0; $i < 65; $i++) {
        Message::factory()->create(['conversation_id' => $conversation->id, 'body' => 'QA history '.$i]);
    }
    $key = $component === Inbox::class ? 'thread' : 'messages';
    Livewire::test($component)->call('select', $conversation->id)
        ->assertViewHas($key, fn ($rows) => $rows->count() === 60 && $rows->last()->body === 'QA history 64' && $rows->first()->body === 'QA history 5')
        ->call('loadEarlier')->assertViewHas($key, fn ($rows) => $rows->count() === 65 && $rows->first()->body === 'QA history 0');
})->with([Inbox::class, LiveChatBell::class]);

it('does not read or mark non-website conversations through the live drawer', function (string $path) {
    $conversation = Conversation::factory()->create(['channel' => Channel::Whatsapp, 'unread_count' => 4]);
    $component = Livewire::test(LiveChatBell::class);
    if ($path === 'select') {
        $component->call('select', $conversation->id)->assertForbidden();
    } else {
        expect(fn () => $component->set('selectedId', $conversation->id))
            ->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
    }
    expect($conversation->refresh()->unread_count)->toBe(4);
})->with(['select', 'hydrate']);

it('keeps the website composer free of WhatsApp warnings and read-only staff cannot reply', function () {
    $conversation = Conversation::factory()->create(['channel' => Channel::WebChat, 'window_expires_at' => null]);
    $reader = $this->userWithRole('editor');
    $reader->givePermissionTo('inbox.view');
    Livewire::actingAs($reader)->test(Inbox::class)->call('select', $conversation->id)
        ->assertDontSee('24-hour window is closed')->assertSee('Read-only access')
        ->set('draft', 'Unauthorized reply')->call('send')->assertForbidden();
    expect($conversation->messages()->count())->toBe(0);
});

it('scopes advanced lead statistics and drawer activity to the existing owner policy', function () {
    $sales = $this->userWithRole('sales');
    Lead::factory()->create(['owner_id' => $sales->id]);
    $foreign = Lead::factory()->create(['owner_id' => $this->owner->id]);
    Livewire::actingAs($sales)->test(LeadsIndex::class)->assertViewHas('workspaceStats', fn ($stats) => $stats['matching'] === 1)
        ->set('drawerId', $foreign->id)->assertForbidden();
});

it('filters actual lead activity without changing recorded history', function () {
    $lead = Lead::factory()->create();
    Activity::create(['lead_id' => $lead->id, 'type' => 'note', 'body' => 'QA design note', 'occurred_at' => now()]);
    Activity::create(['lead_id' => $lead->id, 'type' => 'call', 'body' => 'QA call outcome', 'occurred_at' => now()]);
    Livewire::test(LeadShow::class, ['lead' => $lead])->set('activityType', 'note')
        ->assertSee('QA design note')->assertDontSee('QA call outcome');
    expect(Activity::where('lead_id', $lead->id)->count())->toBe(2);
});
