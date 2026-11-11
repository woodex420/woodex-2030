<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Conversations;

use App\Domain\Conversations\Actions\SendConversationMessage;
use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Exceptions\ConsentRequired;
use App\Domain\Conversations\Exceptions\OutsideWindow;
use App\Domain\Conversations\Models\CannedReply;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use App\Integrations\WhatsApp\Contracts\WhatsAppGateway;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Livewire\Concerns\WithConversationHistory;
use App\Models\User;
use App\Settings\BusinessSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

/**
 * The unified inbox (CNV-001, CNV-002): every conversation on the left, one thread on the right.
 *
 * It is deliberately plain: a list, a thread, a composer. The composer knows Meta's 24-hour rule (CNV-005) — when
 * the window is closed it says so and refuses to send free-form text, because a message Meta rejects is worse
 * than no message. Real-time updates arrive with the rest of the phase; until then the thread refreshes on every
 * action and the list polls.
 */
#[Layout('layouts::admin')]
#[Title('Inbox')]
class Inbox extends Component
{
    use InteractsWithAdmin;
    use WithConversationHistory;
    use WithFileUploads;
    use WithPagination;

    #[Url(except: 'open')]
    public string $status = 'open';

    #[Url(except: '')]
    public string $channel = '';

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: false)]
    public bool $unreadOnly = false;

    #[Url(except: '')]
    public string $assignment = '';

    #[Locked]
    public ?int $selectedId = null;

    #[Locked]
    public ?int $pendingSelectionId = null;

    /** Mobile list navigation never detaches the composer from its conversation. */
    public bool $showList = false;

    public string $draft = '';

    /** @var TemporaryUploadedFile|null A photograph, PDF, voice note or clip to send (CNV-008). */
    public $attachment = null;

    public string $note = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Conversation::class);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['status', 'channel', 'search', 'unreadOnly', 'assignment'], true)) {
            $this->resetPage();
        }
    }

    public function filterStatus(string $filter): void
    {
        abort_unless(in_array($filter, ['all', 'open', 'pending', 'closed', 'unread'], true), 422);
        $this->status = $filter === 'unread' ? 'all' : $filter;
        $this->unreadOnly = $filter === 'unread';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Conversation> */
    public function conversations(): LengthAwarePaginator
    {
        return $this->query()->paginate(60);
    }

    /** @return array{open: int, pending: int, unread: int, closed: int} */
    public function counts(): array
    {
        $base = $this->query(false);

        return [
            'open' => (clone $base)->where('status', ConversationStatus::Open->value)->count(),
            'pending' => (clone $base)->where('status', ConversationStatus::Pending->value)->count(),
            'unread' => (clone $base)->where('unread_count', '>', 0)->count(),
            'closed' => (clone $base)->where('status', ConversationStatus::Closed->value)->count(),
        ];
    }

    public function select(int $id): void
    {
        $conversation = Conversation::query()->findOrFail($id);
        $this->authorize('view', $conversation);
        $this->showList = false;

        if ($this->selectedId === $id) {
            $this->pendingSelectionId = null;
            if ($this->historyBeforeId === null) {
                $conversation->markRead();
            }

            return;
        }
        if (trim($this->draft) !== '' || trim($this->note) !== '' || $this->attachment !== null) {
            $this->pendingSelectionId = $id;

            return;
        }
        $this->activateConversation($conversation);
    }

    public function cancelSwitch(): void
    {
        $this->pendingSelectionId = null;
    }

    public function confirmSwitch(): void
    {
        if ($this->pendingSelectionId === null) {
            return;
        }
        $conversation = Conversation::query()->findOrFail($this->pendingSelectionId);
        $this->authorize('view', $conversation);
        $this->activateConversation($conversation);
    }

    public function backToList(): void
    {
        $this->showList = true;
        $this->pendingSelectionId = null;
    }

    private function activateConversation(Conversation $conversation): void
    {
        $this->selectedId = $conversation->id;
        $this->pendingSelectionId = null;
        $this->showList = false;
        $this->resetHistory();
        $this->resetValidation();
        $this->draft = '';
        $this->note = '';
        $this->attachment = null;
        $conversation->markRead();
        $this->dispatch('chat-scroll', reset: true);
    }

    /**
     * Saved replies matching what was typed after "/" (CNV-004): the picker under the composer.
     *
     * @return list<array{id: int, title: string, locale: string, snippet: string}>
     */
    #[Computed]
    public function cannedMatches(): array
    {
        if ($this->selectedId === null || ! str_starts_with($this->draft, '/')) {
            return [];
        }

        return array_map(
            static fn (CannedReply $reply): array => [
                'id' => $reply->id,
                'title' => $reply->title,
                'locale' => $reply->locale,
                'snippet' => str($reply->body)->limit(72)->toString(),
            ],
            CannedReply::matchesFor(substr($this->draft, 1)),
        );
    }

    /**
     * Pick a saved reply: it lands in the composer filled in for this client, ready to edit before sending.
     */
    public function useCanned(int $id, BusinessSettings $business): void
    {
        $conversation = $this->selected();

        if ($conversation === null) {
            return;
        }

        $reply = CannedReply::query()->active()->find($id);

        if ($reply === null) {
            return;
        }

        $this->draft = $reply->renderFor($conversation, $business);
        $reply->increment('usage_count');
    }

    /**
     * The moment a file lands, say if WhatsApp will not take it — better than a refusal after Send.
     */
    public function updatedAttachment(): void
    {
        if ($this->attachment === null) {
            return;
        }

        $mime = strtolower((string) $this->attachment->getMimeType());
        $ok = str_starts_with($mime, 'image/') || str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/') || $mime === 'application/pdf';

        if (! $ok) {
            $this->toast('WhatsApp takes images, PDFs, audio and video only.', 'error');
            $this->attachment = null;

            return;
        }

        $limit = str_starts_with($mime, 'image/') ? 5 : 16;

        if ($this->attachment->getSize() > $limit * 1_000_000) {
            $this->toast('That file is over '.$limit.' MB — WhatsApp will not take it.', 'error');
            $this->attachment = null;
        }
    }

    public function removeAttachment(): void
    {
        $this->attachment = null;
    }

    public function send(SendConversationMessage $action): void
    {
        $conversation = $this->selected();

        if ($conversation === null || ($this->attachment === null && trim($this->draft) === '')) {
            return;
        }

        $this->authorize('reply', $conversation);

        try {
            // CNV-008: with a file attached, the typed text becomes the caption on the media.
            $message = $this->attachment !== null
                ? $action->media($conversation, $this->attachment, $this->draft, Auth::user())
                : $action->handle($conversation, $this->draft, Auth::user());
        } catch (OutsideWindow|ConsentRequired $exception) {
            $this->toast($exception->getMessage(), 'error');

            return;
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->toast('WhatsApp did not take that message. It is saved as failed — try again.', 'error');

            return;
        }

        $this->draft = '';
        $this->attachment = null;
        $this->resetHistory();
        $this->dispatch('chat-scroll', reset: true);
        $this->toast($message->status === MessageStatus::Sent ? 'Sent.' : 'Saved as failed — WhatsApp refused it.');
    }

    public function addNote(SendConversationMessage $action): void
    {
        $conversation = $this->selected();
        $user = Auth::user();

        if ($conversation === null || trim($this->note) === '' || ! $user instanceof User) {
            return;
        }

        $this->authorize('reply', $conversation);
        $action->note($conversation, $this->note, $user);

        $this->note = '';
        $this->resetHistory();
        $this->dispatch('chat-scroll', reset: true);
        $this->toast('Note added — the client never sees it.');
    }

    public function assign(int $userId): void
    {
        $conversation = $this->selected();

        if ($conversation === null) {
            return;
        }

        $this->authorize('update', $conversation);
        if ($userId > 0) {
            validator(['assignee' => $userId], ['assignee' => [Rule::exists('users', 'id')->where('is_active', true)]])->validate();
        }
        $conversation->update(['assignee_id' => $userId > 0 ? $userId : null]);
        $this->toast($userId > 0 ? 'Assigned.' : 'Unassigned.');
    }

    public function close(): void
    {
        $conversation = $this->selected();

        if ($conversation === null) {
            return;
        }

        $this->authorize('update', $conversation);
        $conversation->update([
            'status' => ConversationStatus::Closed,
            'closed_at' => now(),
            'closed_by' => Auth::id(),
        ]);
        $this->toast('Conversation closed.');
    }

    public function reopen(): void
    {
        $conversation = $this->selected();

        if ($conversation === null) {
            return;
        }

        $this->authorize('update', $conversation);
        $conversation->update(['status' => ConversationStatus::Open, 'closed_at' => null, 'closed_by' => null]);
        $this->toast('Reopened.');
    }

    /** Poll the thread: new messages and delivery ticks appear without a reload until broadcasting lands (CNV-006). */
    public function refresh(): void
    {
        $this->dispatch('chat-scroll');
    }

    public function render(WhatsAppGateway $whatsapp): View
    {
        $this->authorize('viewAny', Conversation::class);
        $conversation = $this->selected();
        $window = $this->historyWindow($conversation, authors: true);

        return view('livewire.admin.conversations.inbox', [
            'conversations' => $this->conversations(),
            'counts' => $this->counts(),
            'selected' => $conversation,
            'thread' => $window['messages'],
            'hasEarlier' => $window['hasEarlier'],
            'configured' => $whatsapp->isConfigured(),
            'channels' => Channel::cases(),
            'staff' => $this->staff(),
        ]);
    }

    protected function historyConversation(): ?Conversation
    {
        return $this->selected();
    }

    private function selected(): ?Conversation
    {
        $conversation = $this->selectedId === null ? null : Conversation::query()->find($this->selectedId);
        if ($conversation !== null) {
            $this->authorize('view', $conversation);
        }

        return $conversation;
    }

    /** @return Builder<Conversation> */
    private function query(bool $withStatus = true): Builder
    {
        $status = ConversationStatus::tryFrom($this->status);

        return Conversation::query()
            ->with(['contact', 'assignee'])
            ->when($withStatus && $status !== null, fn (Builder $q): Builder => $q->where('status', $status->value))
            ->when($this->assignment === 'me', fn (Builder $q): Builder => $q->where('assignee_id', $this->user()->id))
            ->when($this->assignment === 'unassigned', fn (Builder $q): Builder => $q->whereNull('assignee_id'))
            ->when($this->channel !== '', fn (Builder $q): Builder => $q->where('channel', $this->channel))
            ->when($withStatus && $this->unreadOnly, fn (Builder $q): Builder => $q->where('unread_count', '>', 0))
            ->when($this->search !== '', function (Builder $q): Builder {
                $term = '%'.$this->search.'%';

                return $q->where(function (Builder $inner) use ($term): void {
                    $inner->where('last_preview', 'like', $term)
                        ->orWhere('external_number', 'like', $term)
                        ->orWhereHas('contact', fn (Builder $c): Builder => $c->where('name', 'like', $term));
                });
            })
            ->orderByDesc('last_message_at')->orderByDesc('id');
    }
}
