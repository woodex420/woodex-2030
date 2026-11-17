<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\Conversations\CannedRepliesIndex;
use App\Livewire\Admin\Crm\AssignmentRules;
use App\Livewire\Admin\System\UsersIndex;

/**
 * System → Access & team (deep QA 21 Oct, slice B): the eleven System entries become four pages
 * with their buttons inside. Who can sign in, who gets which enquiry and which reply is canned —
 * the three questions asked when someone joins or leaves the studio.
 */
final class SystemAccessHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Access & team',
            'description' => 'Accounts and roles, who receives a new enquiry, and the replies the team sends every day.',
            'breadcrumbs' => ['System' => null, 'Access & team' => null],
            'tabs' => [
                ['id' => 'users', 'label' => 'Users & roles', 'component' => UsersIndex::class, 'permission' => 'users.manage'],
                ['id' => 'assignment', 'label' => 'Lead assignment', 'component' => AssignmentRules::class, 'permission' => 'settings.manage'],
                ['id' => 'canned', 'label' => 'Canned replies', 'component' => CannedRepliesIndex::class, 'permission' => 'settings.manage'],
            ],
        ];
    }
}
