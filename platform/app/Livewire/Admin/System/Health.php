<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Domain\System\Control\ControlPanel;
use App\Domain\System\Health\SystemHealthReport;
use App\Domain\System\Models\IntegrationLog;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * System health (SYS-008): the same collector /health reads — cron heartbeat, queue, failed jobs, disk,
 * backups, scheduled tasks, database size, integrations. S5 adds the test-email button.
 */
#[Layout('layouts::admin')]
#[Title('System health')]
class Health extends Component
{
    public function render(SystemHealthReport $health): View
    {
        $this->authorize('system.health');

        return view('livewire.admin.system.health', [
            'checks' => $health->checks(),
            'environment' => $health->environment(),
            'taskRuns' => $health->taskRuns(),
            // What the dashboard's "Automations failing" counted, as rows (master list T-044).
            'failures' => app(ControlPanel::class)->failures(),
            'integrationErrors' => IntegrationLog::query()->where('ok', false)->latest('id')->limit(10)->get(),
        ]);
    }

    /**
     * Send a test email to the signed-in admin's own address (S5): the fastest way to see whether the
     * mail settings work, before blaming a client for "I never got the quotation".
     */
    public function sendTestMail(): void
    {
        $this->authorize('system.health');
        $user = auth()->user();

        if ($user === null || $user->email === null || $user->email === '') {
            $this->dispatch('toast', message: 'Your profile has no email address to send to.', type: 'error');

            return;
        }

        $sentAt = now()->timezone('Asia/Karachi')->format('D j M, g:i a');

        try {
            Mail::raw(
                "This is a test email from Woodex OS (System → Health), sent {$sentAt} PKT.\n\n"
                .'If you can read this, outgoing mail works.',
                function ($message) use ($user): void {
                    $message->to($user->email)->subject('Woodex OS test email');
                },
            );

            $this->dispatch('toast', message: "Test email sent to {$user->email}.", type: 'success');
        } catch (\Throwable $exception) {
            report($exception);
            $this->dispatch('toast', message: 'Sending failed: '.$exception->getMessage(), type: 'error');
        }
    }
}
