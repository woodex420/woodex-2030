<?php

declare(strict_types=1);

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Models\Conversation;
use App\Livewire\Admin\Conversations\Inbox;
use App\Livewire\Admin\Conversations\LiveChatBell;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = $this->userWithRole('owner');
    $this->actingAs($this->owner);
});

it('does not discard the reply or history when the selected thread is clicked again', function (string $class) {
    $thread = Conversation::factory()->create(['channel' => Channel::WebChat]);
    Livewire::test($class)->call('select', $thread->id)->set('draft', 'Unsent synthetic reply')
        ->call('loadEarlier')->call('select', $thread->id)
        ->assertSet('draft', 'Unsent synthetic reply')->assertSet('historyLimit', 120);
})->with([Inbox::class, LiveChatBell::class]);

it('keeps the selected reply and note while filtering the inbox list', function () {
    $thread = Conversation::factory()->create(['channel' => Channel::WebChat]);
    Livewire::test(Inbox::class)->call('select', $thread->id)->set('draft', 'Keep this reply')->set('note', 'Keep this note')
        ->set('search', 'unmatched synthetic filter')->assertSet('selectedId', $thread->id)
        ->set('channel', 'web_chat')->set('assignment', 'me')->call('filterStatus', 'closed')
        ->assertSet('selectedId', $thread->id)->assertSet('draft', 'Keep this reply')->assertSet('note', 'Keep this note');
    expect($thread->messages()->count())->toBe(0);
});

it('requires an explicit discard before switching inbox threads with unsent work', function () {
    $first = Conversation::factory()->create(['channel' => Channel::WebChat]);
    $second = Conversation::factory()->create(['channel' => Channel::WebChat, 'unread_count' => 3]);
    $component = Livewire::test(Inbox::class)->call('select', $first->id)->set('draft', 'First visitor reply')->set('note', 'First visitor note')
        ->call('select', $second->id)->assertSet('selectedId', $first->id)->assertSet('pendingSelectionId', $second->id)
        ->assertSet('draft', 'First visitor reply')->assertSet('note', 'First visitor note');
    expect($second->fresh()->unread_count)->toBe(3);
    $component->call('cancelSwitch')->assertSet('pendingSelectionId', null)->assertSet('draft', 'First visitor reply')
        ->call('select', $second->id)->call('confirmSwitch')->assertSet('selectedId', $second->id)
        ->assertSet('pendingSelectionId', null)->assertSet('draft', '')->assertSet('note', '');
    expect($first->messages()->count())->toBe(0)->and($second->messages()->count())->toBe(0)
        ->and($second->fresh()->unread_count)->toBe(0);
});

it('protects direct drawer selection just like its open-thread action', function () {
    $first = Conversation::factory()->create(['channel' => Channel::WebChat]);
    $second = Conversation::factory()->create(['channel' => Channel::WebChat, 'unread_count' => 2]);
    Livewire::test(LiveChatBell::class)->call('select', $first->id)->set('draft', 'Keep drawer reply')
        ->call('select', $second->id)->assertSet('selectedId', $first->id)->assertSet('pendingId', $second->id)
        ->assertSet('draft', 'Keep drawer reply')->call('cancelSwitch')->assertSet('pendingId', null)
        ->call('select', $second->id)->call('switchPending')->assertSet('selectedId', $second->id)->assertSet('draft', '');
    expect($first->messages()->count())->toBe(0)->and($second->messages()->count())->toBe(0);
});

it('keeps the mobile back action on the same draft and restores it on reselect', function () {
    $thread = Conversation::factory()->create(['channel' => Channel::WebChat]);
    Livewire::test(Inbox::class)->call('select', $thread->id)->set('draft', 'Mobile retained reply')
        ->call('backToList')->assertSet('showList', true)->assertSet('selectedId', $thread->id)
        ->call('select', $thread->id)->assertSet('showList', false)->assertSet('draft', 'Mobile retained reply');
});

it('paginates past sixty conversations deterministically and resets only the list page on filters', function () {
    $first = Conversation::factory()->create(['channel' => Channel::WebChat, 'last_message_at' => now()]);
    Conversation::factory()->count(60)->create(['channel' => Channel::WebChat, 'last_message_at' => $first->last_message_at]);
    Livewire::test(Inbox::class)->assertViewHas('conversations', fn ($rows) => $rows->total() === 61 && $rows->count() === 60 && ! $rows->contains('id', $first->id))
        ->call('nextPage')->assertViewHas('conversations', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $first->id)
        ->call('select', $first->id)->set('draft', 'Paging retains reply')->set('search', 'no matching conversation')
        ->assertSet('paginators.page', 1)->assertSet('selectedId', $first->id)->assertSet('draft', 'Paging retains reply');
});

it('rejects browser replacement of the selected thread', function (string $class) {
    $thread = Conversation::factory()->create(['channel' => Channel::WebChat]);
    $component = Livewire::test($class);
    expect(fn () => $component->set('selectedId', $thread->id))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([Inbox::class, LiveChatBell::class]);

it('rechecks permission when confirming a pending switch', function () {
    $first = Conversation::factory()->create(['channel' => Channel::WebChat]);
    $second = Conversation::factory()->create(['channel' => Channel::WebChat, 'unread_count' => 3]);
    $component = Livewire::test(Inbox::class)->call('select', $first->id)->set('draft', 'Permission-sensitive draft')->call('select', $second->id);
    $this->owner->syncRoles(['editor']);
    $component->call('confirmSwitch')->assertForbidden();
    expect($second->fresh()->unread_count)->toBe(3)->and($second->messages()->count())->toBe(0);
});

it('protects note-only and attachment-only drafts without sending anything', function (string $kind) {
    $first = Conversation::factory()->create(['channel' => Channel::Whatsapp]);
    $second = Conversation::factory()->create(['channel' => Channel::WebChat]);
    $component = Livewire::test(Inbox::class)->call('select', $first->id);
    if ($kind === 'note') {
        $component->set('note', 'Only an internal note');
    } else {
        $component->set('attachment', \Illuminate\Http\UploadedFile::fake()->create('unsent.pdf', 1, 'application/pdf'));
    }
    $component->call('select', $second->id)->assertSet('selectedId', $first->id)->assertSet('pendingSelectionId', $second->id)
        ->call('backToList')->call('select', $first->id)->assertSet('selectedId', $first->id);
    if ($kind === 'note') {
        $component->assertSet('note', 'Only an internal note');
    } else {
        expect($component->get('attachment')->getClientOriginalName())->toBe('unsent.pdf');
    }
    $component->call('select', $second->id)->call('confirmSwitch')->assertSet('note', '')->assertSet('attachment', null);
    expect($first->messages()->count())->toBe(0)->and($second->messages()->count())->toBe(0);
})->with(['note', 'attachment']);

it('rejects browser replacement of a pending switch destination', function (string $class, string $property) {
    $component = Livewire::test($class);
    expect(fn () => $component->set($property, 123))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([[Inbox::class, 'pendingSelectionId'], [LiveChatBell::class, 'pendingId']]);

it('sends a kept draft only to the original visitor after a cancelled switch', function (string $class) {
    $first = Conversation::factory()->create(['channel' => Channel::WebChat]);
    $second = Conversation::factory()->create(['channel' => Channel::WebChat]);
    Livewire::test($class)->call('select', $first->id)->set('draft', 'Reply belongs only to visitor one')
        ->call('select', $second->id)->call('cancelSwitch')->call('send')->assertSet('draft', '');
    expect($first->messages()->sole()->body)->toBe('Reply belongs only to visitor one')
        ->and($second->messages()->count())->toBe(0);
})->with([Inbox::class, LiveChatBell::class]);

it('does not read arrivals while the mobile list is shown but reads them on returning to the latest thread', function () {
    $thread = Conversation::factory()->create(['channel' => Channel::WebChat]);
    $component = Livewire::test(Inbox::class)->call('select', $thread->id)->set('draft', 'Retain while browsing list')->call('backToList');
    $thread->update(['unread_count' => 2]);
    $component->call('refresh')->assertSet('draft', 'Retain while browsing list');
    expect($thread->fresh()->unread_count)->toBe(2);
    $component->call('select', $thread->id)->assertSet('draft', 'Retain while browsing list')->assertSet('showList', false);
    expect($thread->fresh()->unread_count)->toBe(0);
});
