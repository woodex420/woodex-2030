<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use Livewire\Attributes\Title;

/**
 * Who has actually bought (slice J): a contact with an accepted quotation or an open project. Read from the
 * records rather than the `is_client` flag, because a project can exist without the flag ever being flipped.
 */
#[Title('Customers')]
final class CustomersIndex extends ContactBook
{
    protected function mode(): string
    {
        return 'customers';
    }
}
