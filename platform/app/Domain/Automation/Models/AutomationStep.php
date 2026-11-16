<?php

declare(strict_types=1);

namespace App\Domain\Automation\Models;

use App\Domain\Automation\Enums\StepKind;
use App\Domain\Crm\Models\Lead;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of a sequence (AUT-001). Payload columns are read by kind; the rest stay null. */
class AutomationStep extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => StepKind::class,
            'wait_minutes' => 'integer',
            'task_due_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<AutomationSequence, $this> */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(AutomationSequence::class, 'sequence_id');
    }

    /** What the timeline shows for this step, with the studio's variables filled for one lead. */
    public function describe(?Lead $lead = null): string
    {
        $name = (string) ($lead?->contact->name ?? 'the client');

        return match ($this->kind) {
            StepKind::Wait => 'Wait '.$this->describeDuration((int) $this->wait_minutes),
            StepKind::SendTemplate => 'Send the mapped '.($this->template_event ?? 'follow-up').' template',
            StepKind::CreateTask => 'Task: '.$this->render($this->task_title ?? 'Follow up', $name),
            StepKind::ChangeStage => 'Move to '.($this->stage_key ?? '?'),
        };
    }

    public function describeDuration(int $minutes): string
    {
        return $minutes % 1440 === 0 && $minutes >= 1440
            ? ($minutes / 1440).' day'.($minutes / 1440 === 1 ? '' : 's')
            : $minutes.' min';
    }

    private function render(string $text, string $name): string
    {
        return str_replace(['{first_name}', '{studio_name}'], [$name, 'Woodex Interior'], $text);
    }
}
