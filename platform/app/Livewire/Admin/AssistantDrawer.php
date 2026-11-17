<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Assistant\Assistant;
use App\Livewire\Concerns\InteractsWithAdmin;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Slice M: the assistant drawer, mounted in the header on every admin page. It is a thin shell over the
 * Assistant engine — chips ask whitelist questions by id, the input box matches by keyword, and every
 * answer arrives with the records it read. The conversation lives only in this component: nothing is
 * stored, and reloading starts a clean thread, which the drawer says out loud.
 */
class AssistantDrawer extends Component
{
    use InteractsWithAdmin;

    public string $draft = '';

    /**
     * @var list<array{question: string, answer: array{headline: string, rows: list<array{label: string, detail: string, url: string}>, source: string, refused: bool, total_minor: ?int}}>
     */
    public array $turns = [];

    public function askChip(string $id): void
    {
        $label = collect(Assistant::QUESTIONS)->firstWhere('id', $id)['label'] ?? $id;

        $this->ask($id, $label);
    }

    public function askDraft(): void
    {
        $text = trim($this->draft);

        if ($text === '') {
            return;
        }

        $this->draft = '';
        $this->ask($text, $text);
    }

    public function clearThread(): void
    {
        $this->turns = [];
    }

    public function render(): View
    {
        return view('livewire.admin.assistant-drawer', [
            'questions' => Assistant::questionsFor($this->user()),
        ]);
    }

    private function ask(string $engineText, string $shownQuestion): void
    {
        $answer = app(Assistant::class)->ask($engineText, $this->user());

        $this->turns[] = ['question' => $shownQuestion, 'answer' => $answer->toArray()];
        $this->turns = array_slice($this->turns, -20);
    }
}
