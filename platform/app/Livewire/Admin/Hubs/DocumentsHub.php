<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\Sales\DescriptionLibrary;
use App\Livewire\Admin\Sales\DocumentDesigner;
use App\Livewire\Admin\Sales\RateBook;

/**
 * P10.1: the Documents workflow page — the owner's second example: descriptions, document design
 * and the rate book, the content a quote is built from.
 */
final class DocumentsHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Documents',
            'description' => 'The content quotes are built from — descriptions, the document design and the rate book.',
            'breadcrumbs' => ['Sales' => null, 'Documents' => null],
            'tabs' => [
                ['id' => 'descriptions', 'label' => 'Descriptions', 'component' => DescriptionLibrary::class, 'permission' => 'quotes.view'],
                ['id' => 'design', 'label' => 'Document design', 'component' => DocumentDesigner::class, 'permission' => 'settings.manage'],
                ['id' => 'rate-book', 'label' => 'Rate book', 'component' => RateBook::class, 'permission' => 'quotes.view'],
            ],
        ];
    }
}
