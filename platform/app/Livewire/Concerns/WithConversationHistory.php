<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

/** Reach every stored message through keyset windows; never render an unbounded thread. */
trait WithConversationHistory
{
    public int $historyLimit = 60;

    #[Locked]
    public ?int $historyBeforeId = null;

    #[Locked]
    public ?int $historyOldestId = null;

    #[Locked]
    public ?int $historyConversationId = null;

    /** @var list<array{before: ?int, limit: int}> */
    #[Locked]
    public array $historyPageStack = [];

    abstract protected function historyConversation(): ?Conversation;

    public function loadEarlier(): void
    {
        $conversation = $this->historyConversation();
        if ($conversation === null) {
            return;
        }
        if ($this->historyLimit < 600) {
            $this->historyLimit = min(600, max(60, $this->historyLimit) + 60);

            return;
        }
        if ($this->historyOldestId !== null && $conversation->messages()->where('id', '<', $this->historyOldestId)->exists()) {
            $this->historyPageStack[] = ['before' => $this->historyBeforeId, 'limit' => min(600, $this->historyLimit)];
            $this->historyBeforeId = $this->historyOldestId;
            $this->historyLimit = 60;
            $this->dispatch('chat-history-page');
        }
    }

    public function newerHistory(): void
    {
        $conversation = $this->historyConversation();
        if ($conversation === null) {
            return;
        }
        $page = array_pop($this->historyPageStack);
        if ($page !== null) {
            $this->historyBeforeId = $page['before'];
            $this->historyLimit = $page['limit'];
            if ($this->historyBeforeId === null) {
                $conversation->markRead();
            }
            $this->dispatch('chat-history-page');
        }
    }

    public function latestHistory(): void
    {
        $conversation = $this->historyConversation();
        if ($conversation === null) {
            return;
        }
        $this->resetHistory();
        $conversation->markRead();
        $this->dispatch('chat-scroll', reset: true);
    }

    protected function resetHistory(): void
    {
        $this->historyLimit = 60;
        $this->historyBeforeId = $this->historyOldestId = $this->historyConversationId = null;
        $this->historyPageStack = [];
    }

    /** @return array{messages: Collection<int, Message>, hasEarlier: bool} */
    protected function historyWindow(?Conversation $conversation, bool $authors = false): array
    {
        if ($conversation === null || $conversation->id !== $this->historyConversationId) {
            $this->resetHistory();
            $this->historyConversationId = $conversation?->id;
        }
        $limit = min(600, max(60, $this->historyLimit));
        $rows = $conversation?->messages()
            ->when($authors, fn ($query) => $query->with('author'))
            ->when($this->historyBeforeId !== null, fn ($query) => $query->where('id', '<', $this->historyBeforeId))
            ->latest('id')->limit($limit + 1)->get() ?? new Collection;
        $messages = $rows->take($limit)->reverse()->values();
        $this->historyOldestId = $messages->first()?->id;

        return ['messages' => $messages, 'hasEarlier' => $rows->count() > $limit];
    }
}
