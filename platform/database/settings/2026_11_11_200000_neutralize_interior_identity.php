<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/** Forward-only cleanup: replace known inherited defaults, never overwrite customized values. */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $replacements = [
            'business.legal_name' => ['Woodex Interior', ''],
            'business.brand_name' => ['Woodex Interior', 'Woodex Furniture'],
            'business.tagline' => ['Thoughtful interiors. Natural materials. Spaces that feel unmistakably yours.', ''],
            'business.address' => ['M-71 Zainab Tower, Model Town Link Road, Lahore', ''],
            'business.phone' => ['+923224000768', ''],
            'business.whatsapp' => ['+923224000768', ''],
            'business.email' => ['woodexinterior.pk@gmail.com', ''],
            'business.website' => ['https://woodex.com.pk', ''],
            'sales.execution_terms' => [implode("\n", [
                '50% advance payment with work order.',
                '40% on completion of civil and wood work.',
                '10% on handover.',
                'Payment will be charged on the actual dimension / size of area.',
                'Rates are valid for 15 days as per market rates.',
            ]), ''],
            'sales.design_terms' => [implode("\n", [
                '75% advance payment with work order.',
                '25% on approval of project.',
                'Above quote is exclusive of all applicable taxes.',
                'Advance is nonrefundable.',
            ]), ''],
            'sales.invoice_terms' => [implode("\n", [
                'Payment is due within the days stated on this invoice.',
                'Please share the payment proof on WhatsApp or by replying to the email that carried this invoice.',
                'Work begins after the advance is received and confirmed.',
            ]), ''],
            'sales.signatories' => [[
                ['name' => 'Imtiaz Ahmad', 'title' => 'Director'],
                ['name' => 'Nabeel Afzal', 'title' => 'Marketing Manager'],
            ], []],
        ];

        foreach ($replacements as $property => [$inherited, $replacement]) {
            $this->migrator->update($property, function ($current) use ($inherited, $replacement) {
                // The settings repository decodes nested JSON rows as objects, not associative arrays.
                $comparable = is_array($inherited) ? json_decode(json_encode($current), true) : $current;

                return $comparable === $inherited ? $replacement : $current;
            });
        }
    }
};
