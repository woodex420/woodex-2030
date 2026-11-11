<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\Crm\ClientsIndex;
use App\Livewire\Admin\Crm\CompaniesIndex;
use App\Livewire\Admin\Crm\ContactsIndex;
use App\Livewire\Admin\Crm\CustomersIndex;
use App\Livewire\Admin\Crm\VisitorsIndex;

/**
 * P10.1: the Contacts workflow page — people and the companies they belong to.
 */
final class ContactsHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Contacts',
            'description' => 'One contact book, five views: everyone, the clients won, the customers who actually bought, the website enquiries that have not converted, and the companies behind them.',
            'breadcrumbs' => ['CRM' => null, 'Contacts' => null],
            'tabs' => [
                ['id' => 'people', 'label' => 'People', 'component' => ContactsIndex::class, 'permission' => 'contacts.view'],
                ['id' => 'clients', 'label' => 'Clients', 'component' => ClientsIndex::class, 'permission' => 'contacts.view'],
                ['id' => 'customers', 'label' => 'Customers', 'component' => CustomersIndex::class, 'permission' => 'contacts.view'],
                ['id' => 'visitors', 'label' => 'Visitors', 'component' => VisitorsIndex::class, 'permission' => 'contacts.view'],
                ['id' => 'companies', 'label' => 'Companies', 'component' => CompaniesIndex::class, 'permission' => 'contacts.view'],
            ],
        ];
    }
}
