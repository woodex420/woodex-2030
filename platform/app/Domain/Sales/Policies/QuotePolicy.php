<?php

declare(strict_types=1);

namespace App\Domain\Sales\Policies;

use App\Domain\Sales\Models\Quote;
use App\Models\User;

/**
 * Quotations (QUO-003/016). Managers, admins and accounts see every quote; sales and designers see the quotes they
 * own and the quotes of leads they own (D-53). Only editable quotes (drafts, changes requested) can be changed.
 */
final class QuotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('quotes.view');
    }

    public function view(User $user, Quote $quote): bool
    {
        return $user->can('quotes.view') && self::inScope($user, $quote);
    }

    public function create(User $user): bool
    {
        return $user->can('quotes.manage');
    }

    public function update(User $user, Quote $quote): bool
    {
        return $user->can('quotes.manage') && self::inScope($user, $quote) && $quote->isEditable();
    }

    public function duplicate(User $user, Quote $quote): bool
    {
        return $user->can('quotes.manage') && self::inScope($user, $quote);
    }

    /** Send, share and revise a quotation the user may work on (QUO-008/013). */
    public function send(User $user, Quote $quote): bool
    {
        return $user->can('quotes.send') && self::inScope($user, $quote);
    }

    /** Record that a client accepted, or that their reply was not a yes (D-112). */
    public function accept(User $user, Quote $quote): bool
    {
        return $user->can('quotes.send') && self::inScope($user, $quote);
    }

    public function revise(User $user, Quote $quote): bool
    {
        return $user->can('quotes.manage') && self::inScope($user, $quote);
    }

    /** Decide approvals (QUO-007): owners and sales managers. */
    public function approve(User $user): bool
    {
        return $user->can('quotes.approve');
    }

    public function delete(User $user, Quote $quote): bool
    {
        return $user->can('quotes.manage') && self::inScope($user, $quote) && $quote->sent_at === null && $quote->isEditable();
    }

    /**
     * Rate book and quote templates (QUO-001/002).
     */
    public function manageCatalog(User $user): bool
    {
        return $user->can('quotes.catalog');
    }

    public static function seesAll(User $user): bool
    {
        return $user->can('leads.view_all') || $user->can('invoices.manage');
    }

    private static function inScope(User $user, Quote $quote): bool
    {
        return self::seesAll($user)
            || $quote->owner_id === $user->id
            || ($quote->lead_id !== null && $quote->lead()->where('owner_id', $user->id)->exists());
    }
}
