<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Conversations;

use App\Domain\Conversations\Models\CannedReply;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Manage the saved replies (CNV-004): the words behind the "/" picker in the inbox. Changing one here
 * changes it for the whole team — that is the point: the studio speaks with one voice.
 */
#[Layout('layouts::admin')]
#[Title('Canned replies')]
class CannedRepliesIndex extends Component
{
    public bool $showEditor = false;

    public ?int $editingId = null;

    public string $title = '';

    public string $body = '';

    public string $locale = 'en';

    public string $keywords = '';

    public function mount(): void
    {
        $this->authorize('settings.manage');
    }

    public function openEditor(): void
    {
        $this->resetValidation();
        $this->editingId = null;
        $this->title = '';
        $this->body = '';
        $this->locale = 'en';
        $this->keywords = '';
        $this->showEditor = true;
    }

    public function edit(int $id): void
    {
        $reply = CannedReply::query()->findOrFail($id);

        $this->resetValidation();
        $this->editingId = $reply->id;
        $this->title = $reply->title;
        $this->body = $reply->body;
        $this->locale = $reply->locale;
        $this->keywords = $reply->keywords;
        $this->showEditor = true;
    }

    public function save(): void
    {
        $this->authorize('settings.manage');

        $this->validate([
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'locale' => ['required', 'in:en,ur'],
            'keywords' => ['nullable', 'string', 'max:160'],
        ]);

        CannedReply::query()->updateOrCreate(
            ['id' => $this->editingId ?? 0],
            [
                'title' => trim($this->title),
                'body' => trim($this->body),
                'locale' => $this->locale,
                'keywords' => trim($this->keywords),
            ],
        );

        $this->showEditor = false;
        $this->dispatch('toast', $this->editingId !== null ? 'Saved reply updated.' : 'Saved reply added.');
    }

    public function toggle(int $id): void
    {
        $this->authorize('settings.manage');

        $reply = CannedReply::query()->findOrFail($id);
        $reply->update(['is_active' => ! $reply->is_active]);

        $this->dispatch('toast', $reply->is_active ? 'Back in the picker.' : 'Hidden from the picker.');
    }

    public function render(): View
    {
        return view('livewire.admin.conversations.canned-replies', [
            'replies' => CannedReply::query()->orderBy('position')->orderBy('title')->get(),
        ]);
    }
}
