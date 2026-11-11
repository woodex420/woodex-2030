<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Domain\System\Actions\SetChangeRequestStatus;
use App\Domain\System\Enums\ChangeRequestStatus;
use App\Domain\System\Models\ChangeRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The change-requests board (SYS-010, TGM-006): every /idea and /issue sent from Telegram, in one flow —
 * new → accepted → planned → in progress → done/rejected. Moving one along notifies whoever asked, which
 * is the loop that keeps them reporting.
 */
#[Layout('layouts::admin')]
#[Title('Change requests')]
class ChangeRequests extends Component
{
    use WithPagination;

    public string $filter = 'open';

    public string $search = '';

    public bool $showNote = false;

    #[Locked]
    public ?int $notingId = null;

    /**
     * The terminal move waiting in the note dialog: change request id => status value.
     *
     * @var array<int, string>
     */
    public array $pendingTerminal = [];

    public string $note = '';

    /**
     * @return list<array{value: string, label: string}>
     */
    public function filters(): array
    {
        return [
            ['value' => 'open', 'label' => 'Open'],
            ['value' => 'all', 'label' => 'All'],
            ...array_map(static fn (ChangeRequestStatus $status): array => ['value' => $status->value, 'label' => $status->label()], ChangeRequestStatus::cases()),
        ];
    }

    /**
     * The moves a card allows from where it stands (the board is a flow, not a jump menu).
     *
     * @return list<array{value: string, label: string}>
     */
    public function nextStatuses(ChangeRequest $request): array
    {
        $options = match ($request->status) {
            ChangeRequestStatus::New => [ChangeRequestStatus::Accepted, ChangeRequestStatus::Rejected],
            ChangeRequestStatus::Accepted => [ChangeRequestStatus::Planned, ChangeRequestStatus::Rejected],
            ChangeRequestStatus::Planned => [ChangeRequestStatus::InProgress, ChangeRequestStatus::Rejected],
            ChangeRequestStatus::InProgress => [ChangeRequestStatus::Done, ChangeRequestStatus::Rejected],
            default => [],
        };

        return array_map(static fn (ChangeRequestStatus $status): array => ['value' => $status->value, 'label' => $status->label()], $options);
    }

    public function setStatus(int $id, string $status, SetChangeRequestStatus $action): void
    {
        $this->authorize('system.change_requests');

        $by = auth()->user();

        if ($by === null) {
            return;
        }

        $request = ChangeRequest::query()->findOrFail($id);
        $next = ChangeRequestStatus::tryFrom($status);

        if ($next === null || ! in_array($next->value, array_column($this->nextStatuses($request), 'value'), true)) {
            $this->dispatch('toast', 'That move is not on this card.', 'error');

            return;
        }

        // Terminal moves carry the why; everything else moves on silently.
        if ($next === ChangeRequestStatus::Done || $next === ChangeRequestStatus::Rejected) {
            $this->pendingTerminal[$id] = $next->value;
            $this->notingId = $id;
            $this->note = '';
            $this->showNote = true;

            return;
        }

        $action->handle($request, $next, $by);
        $this->dispatch('toast', $request->reference().' → '.$next->label().'. The requester has been told.');
    }

    public function saveNote(SetChangeRequestStatus $action): void
    {
        $this->authorize('system.change_requests');

        $by = auth()->user();

        if ($by === null) {
            return;
        }

        $this->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);
        $id = (int) $this->notingId;

        $request = ChangeRequest::query()->findOrFail($id);
        $next = ChangeRequestStatus::tryFrom($this->pendingTerminal[$id] ?? '');

        if ($next === null) {
            $this->showNote = false;

            return;
        }

        $action->handle($request, $next, $by, $this->note);
        unset($this->pendingTerminal[$id]);

        $this->showNote = false;
        $this->note = '';
        $this->dispatch('toast', $request->reference().' → '.$next->label().'. The requester has been told.');
    }

    /**
     * The board, filtered.
     *
     * @return ConcretePaginator<int, ChangeRequest>
     */
    #[Computed]
    public function requests(): ConcretePaginator
    {
        return ChangeRequest::query()
            ->with('requester:id,name')
            ->when($this->filter === 'open', fn ($query) => $query->whereNotIn('status', [ChangeRequestStatus::Done->value, ChangeRequestStatus::Rejected->value]))
            ->when($this->filter !== 'open' && $this->filter !== 'all', fn ($query) => $query->where('status', $this->filter))
            ->when($this->search !== '', function ($query): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $this->search).'%';
                $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('body', 'like', $term));

                // "CR-0007" or just "7" finds the card by its reference.
                $reference = trim(preg_replace('/^cr-?/i', '', $this->search) ?? '');

                if ($reference !== '' && ctype_digit($reference)) {
                    $query->orWhere('id', (int) $reference);
                }
            })
            ->latest('id')
            ->paginate(15);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.admin.system.change-requests');
    }
}
