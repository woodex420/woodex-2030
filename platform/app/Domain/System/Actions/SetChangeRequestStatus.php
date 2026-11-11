<?php

declare(strict_types=1);

namespace App\Domain\System\Actions;

use App\Domain\System\Alerts\AlertRouter;
use App\Domain\System\Enums\ChangeRequestStatus;
use App\Domain\System\Models\ChangeRequest;
use App\Domain\System\Notifications\ChangeRequestUpdatedNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Move an idea or issue along the board (SYS-010, TGM-006): new → accepted → planned → in progress →
 * done/rejected. Whoever reported it hears about the move — on the bell and, when linked, on Telegram —
 * which is what makes "report it from your phone" worth doing: the answer comes back to the same phone.
 */
final class SetChangeRequestStatus
{
    public function handle(ChangeRequest $request, ChangeRequestStatus $status, User $by, ?string $note = null): ChangeRequest
    {
        return DB::transaction(function () use ($request, $status, $by, $note): ChangeRequest {
            $terminal = in_array($status, [ChangeRequestStatus::Done, ChangeRequestStatus::Rejected], true);

            $request->update([
                'status' => $status,
                'resolution_note' => $note !== null && trim($note) !== '' ? trim($note) : $request->resolution_note,
                'resolved_at' => $terminal ? now() : null,
            ]);

            $request->refresh();

            $requester = $request->requester;

            if ($requester !== null && $requester->isNot($by)) {
                app(AlertRouter::class)->send('system.change_request', [$requester], new ChangeRequestUpdatedNotification($request, $by));
            }

            return $request;
        });
    }
}
