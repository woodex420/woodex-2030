<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use Livewire\Attributes\Title;

/**
 * The **Clients** view (Gauzy parity, slice J): people and companies the studio has actually won —
 * `contacts.is_client`, which the OS sets when a quotation is accepted, not a label typed by hand.
 *
 * It reuses the contacts screen unchanged (search, filters, the create form) with the client filter fixed,
 * so there is one list and one set of rules rather than a second copy that can drift.
 */
#[Title('Clients')]
final class ClientsIndex extends ContactsIndex
{
    public function mount(): void
    {
        parent::mount();

        $this->type = 'clients';
    }
}
