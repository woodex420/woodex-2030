<?php

declare(strict_types=1);

namespace App\Domain\Crm\Policies;

use App\Domain\Crm\Models\Contact;
use App\Models\User;

/**
 * Contact access (PRD §7): managers and accounts see everyone; sales see the people they own or
 * have a lead with. Owners pass every check via Gate::before.
 */
final class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contacts.view');
    }

    public function view(User $user, Contact $contact): bool
    {
        return $user->can('contacts.view') && self::inScope($user, $contact);
    }

    public function create(User $user): bool
    {
        return $user->can('contacts.update');
    }

    public function update(User $user, Contact $contact): bool
    {
        return $user->can('contacts.update') && self::inScope($user, $contact);
    }

    public function merge(User $user): bool
    {
        return $user->can('contacts.merge');
    }

    public static function seesAll(User $user): bool
    {
        return $user->can('leads.view_all') || $user->can('invoices.manage');
    }

    private static function inScope(User $user, Contact $contact): bool
    {
        return self::seesAll($user)
            || $contact->owner_id === $user->id
            || $contact->leads()->where('owner_id', $user->id)->exists();
    }
}
