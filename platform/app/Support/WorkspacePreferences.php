<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * How the workspace looks and behaves for one person (master list T-056; documents 05 §2 and 07 §14).
 *
 * Three choices, each of which changes something that can be seen:
 *
 *  - appearance: light, dark, or as the device says. It was kept in each browser only; now it follows the person
 *    to a browser that has not been switched by hand (layouts/admin reads it before the page is painted).
 *  - shortcuts: whether a single key (N, Q, G then D, /) acts from anywhere. A person who dictates, or whose hand
 *    rests on the keyboard, must be able to switch such keys off.
 *  - start: the screen sign-in opens, one of the screens this person can open.
 *
 * Kept on the person's own record (users.preferences). Nobody sets them for somebody else.
 */
final class WorkspacePreferences
{
    /** @var array<string, string> */
    public const array APPEARANCES = ['system' => 'As this device says', 'light' => 'Light', 'dark' => 'Dark'];

    /** The mark in the session of a person who has just signed in for the first time. */
    public const string FIRST_SIGN_IN = 'wx.first_sign_in';

    public static function appearance(?User $user): string
    {
        $chosen = $user?->preference('appearance');

        return is_string($chosen) && isset(self::APPEARANCES[$chosen]) ? $chosen : 'system';
    }

    public static function shortcuts(?User $user): bool
    {
        return $user?->preference('shortcuts') !== false;
    }

    /**
     * The screens this person can choose to start on: what their menu offers today.
     *
     * @return array<string, array{label: string, route: string}>
     */
    public static function startScreens(User $user): array
    {
        $screens = [];
        foreach (AdminNavigation::for($user) as $group) {
            foreach ($group['items'] as $item) {
                // A screen that is a document of its own (the page builder) is no place to land on.
                if (in_array($item['route'], AdminNavigation::FULL_DOCUMENT, true)) {
                    continue;
                }
                $screens[$item['id']] = ['label' => $group['title'].' · '.$item['label'], 'route' => $item['route']];
            }
        }

        return $screens;
    }

    /** The chosen start screen, when it is still one this person can open. Otherwise the dashboard. */
    public static function start(User $user): string
    {
        $chosen = $user->preference('start');

        return is_string($chosen) ? (self::startScreens($user)[$chosen]['route'] ?? 'admin.dashboard') : 'admin.dashboard';
    }

    /**
     * Where sign-in takes this person, as a path of this site: a first sign-in is led to the welcome, every later
     * one to the screen they chose.
     */
    public static function home(User $user, Request $request): string
    {
        if ($request->hasSession() && $request->session()->get(self::FIRST_SIGN_IN) === true) {
            return route('admin.system.preferences', ['welcome' => 1], false);
        }

        return route(self::start($user), [], false);
    }

    /**
     * @param  array{appearance?: mixed, shortcuts?: mixed, start?: mixed}  $choice
     */
    public static function save(User $user, array $choice): void
    {
        $appearance = $choice['appearance'] ?? 'system';
        $start = $choice['start'] ?? '';

        if (! is_string($appearance) || ! isset(self::APPEARANCES[$appearance])) {
            throw ValidationException::withMessages(['appearance' => 'Choose light, dark, or as this device says.']);
        }
        if (! is_string($start) || ($start !== '' && ! isset(self::startScreens($user)[$start]))) {
            throw ValidationException::withMessages(['start' => 'Choose one of the screens you can open.']);
        }

        $user->forceFill(['preferences' => [
            ...((array) $user->preferences),
            'appearance' => $appearance,
            'shortcuts' => (bool) ($choice['shortcuts'] ?? true),
            'start' => $start === '' ? null : $start,
        ]])->save();
    }
}
