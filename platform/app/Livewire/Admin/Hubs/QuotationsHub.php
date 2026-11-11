<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Hubs;

use App\Livewire\Admin\Sales\ProposalsIndex;
use App\Livewire\Admin\Sales\QuotePipeline;
use App\Livewire\Admin\Sales\QuotesIndex;
use App\Livewire\Admin\Sales\QuoteTemplates;
use App\Livewire\Admin\Sales\QuoteTrackingBoard;

/**
 * P10.1: the Quotations workflow page — the owner's first example: all quotes, the tracking
 * board and the templates on one page, in working sequence.
 */
final class QuotationsHub extends Hub
{
    protected function hub(): array
    {
        return [
            'title' => 'Quotations',
            'description' => 'Every quote, its follow-up state and the templates it starts from — one page, in working sequence.',
            'breadcrumbs' => ['Sales' => null, 'Quotations' => null],
            'tabs' => [
                ['id' => 'all', 'label' => 'All quotes', 'component' => QuotesIndex::class, 'permission' => 'quotes.view'],
                // Slice K: the proposal stage and the pipeline view over the same quotations.
                ['id' => 'proposals', 'label' => 'Proposals', 'component' => ProposalsIndex::class, 'permission' => 'quotes.view'],
                ['id' => 'pipeline', 'label' => 'Pipeline', 'component' => QuotePipeline::class, 'permission' => 'quotes.view'],
                ['id' => 'tracking', 'label' => 'Tracking', 'component' => QuoteTrackingBoard::class, 'permission' => 'quotes.view'],
                ['id' => 'templates', 'label' => 'Templates', 'component' => QuoteTemplates::class, 'permission' => 'quotes.view'],
            ],
        ];
    }
}
