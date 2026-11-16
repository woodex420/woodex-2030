<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\Install\DatabaseUpdates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * One-click database update after a deploy (D-58). A plain page on the sign-in layout: the admin layout itself may
 * need the new tables (menu counters), so it must not be used before the update has run.
 */
final class SystemUpdateController
{
    public function show(DatabaseUpdates $updates): View
    {
        return view('admin.system.updates', ['pending' => $updates->pending()]);
    }

    public function apply(DatabaseUpdates $updates): RedirectResponse
    {
        try {
            $log = $updates->apply();
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('update_error', $exception->getMessage());
        }

        activity('system')->causedBy(auth()->user())->log('Applied database updates');

        return redirect()->route('admin.system.updates')->with('update_log', $log);
    }
}
