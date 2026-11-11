<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Models\Conversation;
use App\Livewire\Concerns\InteractsWithAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Website intake, including anonymous/abandoned conversations, from the native SQL store. */
class WebChatLeads extends Component
{
    use InteractsWithAdmin;
    use WithPagination;

    #[Url(as: 'chat_search', except: '')]
    public string $search = '';

    #[Url(as: 'chat_focus', except: '')]
    public string $focus = '';

    #[Url(as: 'chat_status', except: '')]
    public string $status = '';

    #[Url(as: 'chat_from', except: '')]
    public string $from = '';

    #[Url(as: 'chat_to', except: '')]
    public string $to = '';

    public function updating(string $name): void
    {
        if (in_array($name, ['search', 'focus', 'status', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'focus', 'status', 'from', 'to');
        $this->resetPage();
    }

    public function render(): View
    {
        abort_unless($this->user()->can('leads.view') && $this->user()->can('inbox.view'), 403);
        $base = Conversation::query()->where('channel', Channel::WebChat->value);
        if (! $this->user()->can('leads.view_all')) {
            $base->where(fn (Builder $query) => $query->whereNull('lead_id')
                ->orWhereHas('lead', fn (Builder $lead) => $lead->where('owner_id', $this->user()->id)));
        }
        $query = clone $base;
        $search = trim(mb_substr($this->search, 0, 200));
        if ($search !== '') {
            $query->where(fn (Builder $q) => $q->where('subject', 'like', '%'.$search.'%')
                ->orWhereHas('contact', fn (Builder $c) => $c->where('name', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'))
                ->orWhereHas('lead', fn (Builder $l) => $l->where('ref', 'like', '%'.$search.'%')));
        }
        if (in_array($this->status, ['open', 'pending', 'snoozed', 'closed'], true)) {
            $query->where('status', $this->status);
        }
        foreach (['from', 'to'] as $field) {
            if ($this->validDate($this->$field)) {
                // Date controls use PKT; compare against the timezone used by Eloquent timestamp storage.
                $boundary = CarbonImmutable::parse($this->$field, 'Asia/Karachi')->startOfDay();
                $query->where('created_at', $field === 'from' ? '>=' : '<',
                    ($field === 'from' ? $boundary : $boundary->addDay())->setTimezone((string) config('app.timezone')));
            }
        }
        match ($this->focus) {
            'anonymous' => $query->whereNull('contact_id'),
            'qualify' => $query->awaitingService(),
            'unread' => $query->where('unread_count', '>', 0),
            'unassigned' => $query->whereNull('assignee_id'),
            'mine' => $query->where('assignee_id', $this->user()->id),
            'overdue' => $query->whereHas('lead', fn (Builder $l) => $l->whereNull('first_response_at')->where('sla_due_at', '<', now())->open()),
            default => null,
        };

        return view('livewire.admin.crm.web-chat-leads', [
            'threads' => $query->with(['contact', 'lead', 'assignee:id,name', 'messages' => fn ($messages) => $messages->where('direction', 'in')->latest('id')->limit(1)])->withCount('messages')
                ->orderByDesc('last_message_at')->orderByDesc('id')->paginate(20),
            'stats' => [
                'total' => (clone $base)->count(),
                'anonymous' => (clone $base)->whereNull('contact_id')->count(),
                'qualify' => (clone $base)->awaitingService()->count(),
                'unread' => (clone $base)->where('unread_count', '>', 0)->count(),
            ],
            'invalidDate' => ($this->from !== '' && ! $this->validDate($this->from)) || ($this->to !== '' && ! $this->validDate($this->to)),
        ]);
    }

    private function validDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
