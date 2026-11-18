<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Sales rules carried over from the Woodex v2.1 documents (docs/00 §3.4).
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('sales.quote_validity_days', 15);
        $this->migrator->add('sales.round_totals_to_rupee', true);
        $this->migrator->add('sales.lakh_grouping', false);
        $this->migrator->add('sales.execution_terms', implode("\n", [
            '50% advance payment with work order.',
            '40% on completion of civil and wood work.',
            '10% on handover.',
            'Payment will be charged on the actual dimension / size of area.',
            'Rates are valid for 15 days as per market rates.',
        ]));
        $this->migrator->add('sales.design_terms', implode("\n", [
            '75% advance payment with work order.',
            '25% on approval of project.',
            'Above quote is exclusive of all applicable taxes.',
            'Advance is nonrefundable.',
        ]));
        $this->migrator->add('sales.discount_limits_bp', [
            'sales' => 500,
            'designer_pm' => 0,
            'sales_manager' => 1000,
            'admin' => 1000,
            'owner' => 10000,
        ]);
        $this->migrator->add('sales.min_margin_bp', null);
        $this->migrator->add('sales.lost_reasons', [
            'Budget too high',
            'Chose another company',
            'Project postponed',
            'No response',
            'Outside service area',
            'Not a genuine enquiry',
            'Other',
        ]);
        $this->migrator->add('sales.budget_bands', [
            'under-1m' => 'Under Rs 10 lakh',
            '1m-3m' => 'Rs 10–30 lakh',
            '3m-7m' => 'Rs 30–70 lakh',
            '7m-15m' => 'Rs 70 lakh – 1.5 crore',
            '15m-plus' => 'Above Rs 1.5 crore',
        ]);
        $this->migrator->add('sales.sla_first_response_minutes', 15);
        $this->migrator->add('sales.signatories', [
            ['name' => 'Imtiaz Ahmad', 'title' => 'Director'],
            ['name' => 'Nabeel Afzal', 'title' => 'Marketing Manager'],
        ]);
        $this->migrator->add('sales.invoice_number_format', '{quote}-{n}');
        $this->migrator->add('sales.invoice_due_days', 7);
    }
};
