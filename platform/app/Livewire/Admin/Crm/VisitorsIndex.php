<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Crm;

use Livewire\Attributes\Title;

/**
 * Website enquiries that have not converted (slice J). Named "Visitors" to match the reference product, but the
 * screen says what it really holds: enquiries from the form, estimator, booking and chat — not page views, which
 * this OS does not collect.
 */
#[Title('Visitors')]
final class VisitorsIndex extends ContactBook
{
    protected function mode(): string
    {
        return 'visitors';
    }
}
