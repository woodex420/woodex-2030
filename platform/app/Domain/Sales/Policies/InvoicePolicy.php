<?php

declare(strict_types=1);

namespace App\Domain\Sales\Policies;

use App\Domain\Sales\Enums\InvoiceStatus;
use App\Domain\Sales\Models\Invoice;
use App\Models\User;

/**
 * Invoices (INV-003). Whoever may see the money may see the invoices; raising and editing them is accounts' work,
 * and an issued invoice is frozen — only a void (owner) reverses it.
 */
final class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('invoices.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.view');
    }

    /** A draft can still be shaped; anything issued is immutable (INV-003). */
    public function update(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.manage') && $invoice->status === InvoiceStatus::Draft;
    }

    /** Writing an invoice by hand (manual kind) is accounts' work, like editing one. */
    public function create(User $user): bool
    {
        return $user->can('invoices.manage');
    }

    /** Issuing freezes the document and books it in the ledger — drafts only. */
    public function issue(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.manage') && $invoice->status === InvoiceStatus::Draft;
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $user->can('invoices.void') && $invoice->status !== InvoiceStatus::Void;
    }
}
