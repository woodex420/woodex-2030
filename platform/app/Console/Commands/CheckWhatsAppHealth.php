<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Integrations\WhatsApp\Actions\CheckWhatsAppQuality;
use App\Integrations\WhatsApp\Actions\CheckWhatsAppUsage;
use Illuminate\Console\Command;

/**
 * WAP-008 + WAP-010: the daily WhatsApp health pass — the number's quality rating, and the
 * month's cost against the studio's budget. Read-only against Meta; the only side effects
 * are the stored rating, and an alert the day something moves the wrong way.
 */
class CheckWhatsAppHealth extends Command
{
    protected $signature = 'woodex:whatsapp-health';

    protected $description = 'Check the WhatsApp number quality rating and the monthly cost estimate.';

    public function handle(CheckWhatsAppQuality $quality, CheckWhatsAppUsage $usage): int
    {
        $qualityResult = $quality->handle();

        if ($qualityResult['ok']) {
            $this->info('Quality rating: '.($qualityResult['rating'] ?? 'unknown').'.');
        } else {
            $this->line('Quality rating: skipped ('.$qualityResult['error'].').');
        }

        if ($qualityResult['downgraded']) {
            $this->components->warn('Quality downgraded from '.$qualityResult['previous'].' to '.$qualityResult['rating'].'; integrations managers alerted.');
        }

        $usageResult = $usage->handle();
        $this->info(sprintf(
            'This month: %d message(s), %d template(s), estimated Rs %s of budget Rs %s.',
            $usageResult['text'],
            $usageResult['template'],
            number_format($usageResult['estimated_cost']),
            number_format($usageResult['budget']),
        ));

        if ($usageResult['alerted']) {
            $this->components->warn('Monthly budget crossed; integrations managers alerted.');
        }

        return self::SUCCESS;
    }
}
