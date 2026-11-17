<?php

declare(strict_types=1);

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Livewire\Admin\Conversations\LiveChatBell;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
});

it('counts every unread active website conversation without loading more than thirty drawer rows', function () {
    Conversation::factory()->count(35)->create(['channel' => Channel::WebChat, 'status' => 'open', 'unread_count' => 4]);
    Conversation::factory()->create(['channel' => Channel::WebChat, 'status' => 'pending', 'unread_count' => 1]);
    Conversation::factory()->create(['channel' => Channel::WebChat, 'status' => 'open', 'unread_count' => 0]);
    Conversation::factory()->count(2)->create(['channel' => Channel::WebChat, 'status' => 'closed', 'unread_count' => 9]);
    Conversation::factory()->count(2)->create(['channel' => Channel::Whatsapp, 'status' => 'open', 'unread_count' => 9]);
    Livewire::test(LiveChatBell::class)->assertViewHas('unread', 36)->assertViewHas('activeTotal', 37)
        ->assertViewHas('live', fn ($rows) => $rows->count() === 30)
        ->assertViewHas('unreadOutsideList', 7)->assertSee('7 unread conversations are outside this list.')
        ->assertSee('Showing the latest 30 of 37 active conversations.');
});

it('includes a recent inbound presence outside the latest thirty rows', function () {
    Conversation::factory()->count(30)->create(['channel' => Channel::WebChat, 'last_message_at' => now(), 'last_direction' => 'out']);
    Conversation::factory()->create(['channel' => Channel::WebChat, 'last_message_at' => now()->subMinute(), 'last_direction' => 'in']);
    Livewire::test(LiveChatBell::class)->assertViewHas('visitorActive', true)->assertSeeHtml('A visitor is chatting right now');
});

it('keeps closed and non-website presence out of the header totals', function () {
    Conversation::factory()->create(['channel' => Channel::WebChat, 'status' => 'closed', 'last_message_at' => now(), 'last_direction' => 'in', 'unread_count' => 2]);
    Conversation::factory()->create(['channel' => Channel::Whatsapp, 'last_message_at' => now(), 'last_direction' => 'in', 'unread_count' => 2]);
    Livewire::test(LiveChatBell::class)->assertViewHas('unread', 0)->assertViewHas('activeTotal', 0)
        ->assertViewHas('visitorActive', false)->assertViewHas('unreadOutsideList', 0)->assertDontSee('Showing the latest');
});

it('uses a stable newest-ID tie break and does not mark older hidden conversations read', function () {
    $threads = Conversation::factory()->count(35)->create(['channel' => Channel::WebChat, 'last_message_at' => now(), 'unread_count' => 1]);
    Livewire::test(LiveChatBell::class)->assertViewHas('live', fn ($rows) => $rows->first()->id === $threads->last()->id)
        ->call('openDrawer')->assertViewHas('unread', 34)->assertViewHas('unreadOutsideList', 5);
    expect($threads->first()->fresh()->unread_count)->toBe(1)->and($threads->last()->fresh()->unread_count)->toBe(0);
});

it('still announces and opens a new message from a conversation outside the compact list', function () {
    $old = Conversation::factory()->create(['channel' => Channel::WebChat, 'last_message_at' => now()->subDay(), 'unread_count' => 1]);
    Conversation::factory()->count(30)->create(['channel' => Channel::WebChat, 'last_message_at' => now()]);
    $component = Livewire::test(LiveChatBell::class);
    Message::factory()->create(['conversation_id' => $old->id, 'direction' => 'in', 'body' => 'Synthetic older visitor returns']);
    $component->call('poll')->assertDispatched('chat-alert')->assertSet('selectedId', $old->id)->assertSet('drawer', true)
        ->assertSee('Synthetic older visitor returns');
});

it('does not expose totals when inbox permission is absent or revoked', function (bool $revoke) {
    Conversation::factory()->count(35)->create(['channel' => Channel::WebChat, 'unread_count' => 1]);
    if ($revoke) {
        $component = Livewire::test(LiveChatBell::class);
        $this->owner->syncRoles(['editor']);
        $component->call('poll')->assertViewHas('canUse', false)->assertDontSee('Website chat');
    } else {
        Livewire::actingAs($this->userWithRole('editor'))->test(LiveChatBell::class)->assertViewHas('canUse', false)->assertDontSee('Website chat');
    }
})->with([false, true]);
