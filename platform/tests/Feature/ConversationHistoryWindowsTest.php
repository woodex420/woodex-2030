<?php

declare(strict_types=1);

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Livewire\Admin\Conversations\Inbox;
use App\Livewire\Admin\Conversations\LiveChatBell;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
});

function longHistoryConversation(): Conversation
{
    $conversation = Conversation::factory()->create(['channel' => Channel::WebChat, 'status' => 'open']);
    foreach (array_chunk(range(0, 649), 100) as $chunk) {
        Message::query()->insert(array_map(fn (int $i): array => ['conversation_id' => $conversation->id, 'direction' => 'in', 'body' => 'Window fixture '.$i, 'created_at' => now(), 'updated_at' => now()], $chunk));
    }

    return $conversation;
}

function olderHistoryWindow(string $class, Conversation $conversation): mixed
{
    $component = Livewire::test($class)->call('select', $conversation->id);
    for ($i = 0; $i < 10; $i++) {
        $component->call('loadEarlier');
    }

    return $component;
}

it('reaches history beyond 600 without unbounded rendering and returns to newer and latest windows', function (string $class) {
    $conversation = longHistoryConversation();
    $key = $class === Inbox::class ? 'thread' : 'messages';
    $component = olderHistoryWindow($class, $conversation);
    $component->assertViewHas($key, fn ($rows) => $rows->count() === 50 && $rows->first()->body === 'Window fixture 0' && $rows->last()->body === 'Window fixture 49')
        ->assertViewHas('hasEarlier', false)->assertSee('Back to latest messages')
        ->set('draft', 'Unsent draft stays on this thread')->call('newerHistory')
        ->assertViewHas($key, fn ($rows) => $rows->count() === 600 && $rows->first()->body === 'Window fixture 50' && $rows->last()->body === 'Window fixture 649')
        ->assertSet('draft', 'Unsent draft stays on this thread')->call('latestHistory')
        ->assertViewHas($key, fn ($rows) => $rows->count() === 60 && $rows->first()->body === 'Window fixture 590')
        ->assertSet('historyBeforeId', null)->assertSet('historyPageStack', []);
})->with([Inbox::class, LiveChatBell::class]);

it('keeps an older window stable when new messages arrive and does not mark unseen messages read', function (string $class) {
    $conversation = longHistoryConversation();
    $key = $class === Inbox::class ? 'thread' : 'messages';
    $component = olderHistoryWindow($class, $conversation)->set('draft', 'Preserve this draft');
    Message::query()->create(['conversation_id' => $conversation->id, 'direction' => 'in', 'body' => 'New visitor fixture']);
    $conversation->update(['unread_count' => 1]);
    $component->call($class === Inbox::class ? 'refresh' : 'poll')
        ->assertViewHas($key, fn ($rows) => $rows->count() === 50 && ! $rows->contains('body', 'New visitor fixture'))
        ->assertSet('draft', 'Preserve this draft');
    expect($conversation->fresh()->unread_count)->toBe(1);
    $component->call('latestHistory')->assertViewHas($key, fn ($rows) => $rows->last()->body === 'New visitor fixture');
    expect($conversation->fresh()->unread_count)->toBe(0);
})->with([Inbox::class, LiveChatBell::class]);

it('never mixes threads and resets the history window on explicit thread selection', function (string $class) {
    $conversation = longHistoryConversation();
    $other = Conversation::factory()->create(['channel' => Channel::WebChat]);
    Message::query()->create(['conversation_id' => $other->id, 'direction' => 'in', 'body' => 'Different thread fixture']);
    $key = $class === Inbox::class ? 'thread' : 'messages';
    olderHistoryWindow($class, $conversation)->assertDontSee('Different thread fixture')->call('select', $other->id)
        ->assertSet('historyBeforeId', null)->assertSet('historyPageStack', [])
        ->assertViewHas($key, fn ($rows) => $rows->count() === 1 && $rows->first()->body === 'Different thread fixture');
})->with([Inbox::class, LiveChatBell::class]);

it('returns to the latest messages after an explicit website reply', function (string $class) {
    $conversation = longHistoryConversation();
    $key = $class === Inbox::class ? 'thread' : 'messages';
    olderHistoryWindow($class, $conversation)->set('draft', 'Synthetic reply fixture')->call('send')
        ->assertSet('draft', '')->assertSet('historyBeforeId', null)->assertSet('historyPageStack', [])
        ->assertViewHas($key, fn ($rows) => $rows->last()->body === 'Synthetic reply fixture');
    expect($conversation->messages()->count())->toBe(651);
})->with([Inbox::class, LiveChatBell::class]);

it('does not let the browser substitute history cursors', function (string $class) {
    $component = Livewire::test($class);
    expect(fn () => $component->set('historyBeforeId', 1))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([Inbox::class, LiveChatBell::class]);

it('rechecks conversation permissions before paging', function (string $class) {
    $conversation = Conversation::factory()->create(['channel' => Channel::WebChat]);
    $component = Livewire::test($class)->call('select', $conversation->id);
    $this->owner->syncRoles(['editor']);
    $component->call('loadEarlier')->assertForbidden();
})->with([Inbox::class, LiveChatBell::class]);
