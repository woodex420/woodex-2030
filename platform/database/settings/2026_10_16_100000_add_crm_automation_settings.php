<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * P1 part 2: holidays (AUT-004), first-response targets per source + second escalation (CRM-010) and
 * editable lead scoring rules (CRM-011). Defaults reproduce the P1 part 1 behaviour exactly.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        // Fixed-date public holidays in Pakistan. Moon-dependent holidays (Eid, Ashura, 12 Rabi-ul-Awal)
        // are added in Settings when they are announced.
        $this->migrator->add('business.holidays', [
            ['date' => '2026-11-09', 'name' => 'Iqbal Day'],
            ['date' => '2026-12-25', 'name' => 'Quaid-e-Azam Day'],
            ['date' => '2027-02-05', 'name' => 'Kashmir Solidarity Day'],
            ['date' => '2027-03-23', 'name' => 'Pakistan Day'],
            ['date' => '2027-05-01', 'name' => 'Labour Day'],
            ['date' => '2027-05-28', 'name' => 'Youm-e-Takbeer'],
            ['date' => '2027-08-14', 'name' => 'Independence Day'],
            ['date' => '2027-11-09', 'name' => 'Iqbal Day'],
            ['date' => '2027-12-25', 'name' => 'Quaid-e-Azam Day'],
        ]);

        $this->migrator->add('sales.sla_minutes_by_source', [
            'whatsapp' => 10,
            'website_chat' => 10,
            'phone_call' => 10,
        ]);
        $this->migrator->add('sales.sla_escalation_minutes', 60);

        $this->migrator->add('sales.scoring_rules', [
            'base' => 20,
            'budget' => ['15m-plus' => 30, '7m-15m' => 25, '3m-7m' => 15, '1m-3m' => 8, 'under-1m' => 0],
            'size' => [
                ['min_sqft' => 4500, 'points' => 15],
                ['min_sqft' => 2000, 'points' => 10],
                ['min_sqft' => 1, 'points' => 5],
            ],
            'services' => ['fit-out' => 10, 'renovation' => 10, 'turnkey' => 10, 'interior-design' => 10, 'architecture' => 10],
            'lines' => ['project' => 10],
            'sources' => ['referral' => 15, 'returning_client' => 15],
            'lead_types' => ['returning' => 15, 'referral' => 15],
            'timelines' => ['asap' => 5, '1-3-months' => 5],
            'cities' => [],
        ]);
        $this->migrator->add('sales.hot_score', 80);

        $this->migrator->add('notifications.sla_final_roles', ['owner']);
    }
};
