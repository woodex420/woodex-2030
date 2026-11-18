<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Support\AdminNavigation;
use App\Support\ReleaseNotes;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Feature status: every destination of WOODEX OS and where it stands today.
 *
 * The sidebar shows only what can be opened here. This screen is where the rest is told: what is still
 * done in the Agency OS dashboard (with the link, where its address is configured), what is built but
 * switched off, and what is not built yet — each with the migration wave in which it arrives.
 *
 * Read-only. It follows the same permissions as the sidebar: a person is not told about a screen they
 * could not open if it existed.
 */
#[Layout('layouts::admin')]
#[Title('Feature status')]
class FeatureStatus extends Component
{
    public function render(): View
    {
        $groups = AdminNavigation::status(auth()->user());
        $counts = [AdminNavigation::AVAILABLE => 0, AdminNavigation::EXISTING => 0, AdminNavigation::OFF => 0, AdminNavigation::PLANNED => 0];
        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $counts[$item['state']]++;
            }
        }

        return view('livewire.admin.system.feature-status', ['groups' => $groups, 'counts' => $counts, 'releases' => array_slice(ReleaseNotes::all(), 0, 5)]);
    }
}
