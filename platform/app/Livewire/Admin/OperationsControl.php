<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\System\Control\OperationsSummary;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts::admin')]
final class OperationsControl extends Component
{
    public function render(OperationsSummary $summary): View
    {
        $this->authorize('dashboard.view');

        /** @var User $user */
        $user = auth()->user();

        return view('livewire.admin.operations-control', [
            'sections' => $summary->sections($user),
            'reviews' => $summary->reviews($user),
        ]);
    }
}
