<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Projects\Mail\ProjectMail;
use App\Domain\Projects\Models\ProjectUpdate;
use App\Domain\Projects\Notifications\ProjectNotification;
use App\Domain\System\Alerts\AlertRouter;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Sending one update to the client (PRJ-004). The photographs go into the client portal in the next part of this
 * phase; until then the client reads the note in the email and the team sees it in the app and on Telegram.
 *
 * Sharing twice is harmless: the email goes out once, when the update first becomes visible.
 */
final class ShareProjectUpdate
{
    public function handle(ProjectUpdate $update): ProjectUpdate
    {
        if ($update->client_visible) {
            return $update;
        }

        $update->loadMissing(['project.contact', 'author']);

        $update->forceFill([
            'client_visible' => true,
            'shared_at' => now(),
        ])->save();

        $email = $update->project->contact?->email;

        if ($email !== null && $email !== '') {
            Mail::to($email)->queue(new ProjectMail(
                projectId: $update->project_id,
                heading: 'An update on '.$update->project->code,
                body: $update->body,
                buttonLabel: null,
                url: null,
            ));
        }

        app(AlertRouter::class)->send('project.update',
            User::query()->with('telegramLink')->permission('projects.view')->where('is_active', true)->get(),
            new ProjectNotification(
                title: 'Update shared on '.$update->project->code,
                body: Str::limit($update->body, 200),
                url: route('admin.projects.show', $update->project_id),
                kind: 'shared',
            ),
        );

        return $update;
    }
}
