<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\Marketing\AttributionIndex;
use App\Livewire\Admin\Marketing\SocialPlannerIndex;

/**
 * Slice M: the Marketing workflow page — plan the posts, then see which sources actually won work.
 */
final class MarketingHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Marketing',
            'description' => 'The social planner, and the attribution that shows which source brought the enquiries that became work.',
            'breadcrumbs' => ['Marketing' => null],
            'tabs' => [
                ['id' => 'planner', 'label' => 'Social planner', 'component' => SocialPlannerIndex::class, 'permission' => 'social.manage'],
                ['id' => 'attribution', 'label' => 'Attribution', 'component' => AttributionIndex::class, 'permission' => 'social.manage'],
            ],
        ];
    }
}
