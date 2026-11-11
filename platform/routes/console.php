<?php

use App\Domain\System\Models\Automation;
use App\Domain\System\Models\ScheduledTaskRun;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduler: driven by ONE hPanel cron entry every minute (docs/06 §5)
|   /usr/bin/php /home/uXXXX/apps/woodexinterior/platform/artisan schedule:run
|
| Business automations are gated by the owner's on/off switch (Automation → P8.5); a missing
| row means enabled, and a toggle lands within a minute of the cache expiring.
|--------------------------------------------------------------------------
*/

// Recover durable WhatsApp events before draining the same database queue.
Schedule::command('woodex:retry-whatsapp-webhooks')->everyMinute()->withoutOverlapping(5);

// Drain the database queue without a daemon (D-17).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// External endpoints must not delay the staff-alert queue or use the preview's sync connection.
Schedule::command('queue:work database --queue=webhooks --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Cron heartbeat for System → Health.
Schedule::command('woodex:heartbeat')->everyMinute();

// First-response SLA: flag uncontacted leads and alert owner + managers (CRM-010).
Schedule::command('woodex:sla-check')->everyMinute()->withoutOverlapping(5)
    ->when(fn (): bool => Automation::enabled('woodex:sla-check'));

// Task reminders and site-visit reminders to staff (CRM-007, CRM-008).
Schedule::command('woodex:reminders')->everyMinute()->withoutOverlapping(5)
    ->when(fn (): bool => Automation::enabled('woodex:reminders'));

// S4 digests: every linked staff member's morning, and the week that just ended (P5).
Schedule::command('woodex:digest --period=daily')->dailyAt('09:00')
    ->when(fn (): bool => Automation::enabled('woodex:daily-digest'));

Schedule::command('woodex:digest --period=weekly')->weeklyOn(1, '09:00')
    ->when(fn (): bool => Automation::enabled('woodex:weekly-digest'));

// S8: deliver what waited out a quiet period (TGM-008) — plumbing, keep it on.
Schedule::command('woodex:telegram-flush')->everyMinute()->withoutOverlapping(5)
    ->when(fn (): bool => Automation::enabled('woodex:telegram-flush'));

// AUT-001: the sequences heartbeat — every due step, every minute, held sends included.
Schedule::command('woodex:automation-run')->everyMinute()
    ->when(fn (): bool => Automation::enabled('woodex:automation-run'));

// WAP-008/WAP-010: daily WhatsApp health — quality rating + monthly cost against budget.
Schedule::command('woodex:whatsapp-health')->dailyAt('09:10')
    ->when(fn (): bool => Automation::enabled('woodex:whatsapp-health'));

// Monthly CRM report to the owner: the month that just ended, in one message (P8.5).
Schedule::command('woodex:monthly-report')->monthlyOn(1, '09:00')
    ->when(fn (): bool => Automation::enabled('woodex:monthly-report'));

// Quotations nobody answered: chase, warn before the price lapses, lapse what is stale (AUT).
Schedule::command('woodex:quote-follow-ups')->dailyAt('09:00')->withoutOverlapping(30)
    ->when(fn (): bool => Automation::enabled('woodex:quote-follow-ups'));

// Slice E: money follows the job — a reached milestone on an accepted quotation becomes its invoice.
Schedule::command('woodex:invoice-milestones')->hourlyAt(15)->withoutOverlapping(30)
    ->when(fn (): bool => Automation::enabled('woodex:invoice-milestones'));

// Slice E: standing charges — a retainer bills itself on its own rhythm.
Schedule::command('woodex:invoice-recurring')->dailyAt('09:20')->withoutOverlapping(30)
    ->when(fn (): bool => Automation::enabled('woodex:invoice-recurring'));

// Slice I: the money-out side repeats too — rent, salaries and subscriptions post themselves.
Schedule::command('woodex:expense-recurring')->dailyAt('09:10')->withoutOverlapping(30)
    ->when(fn (): bool => Automation::enabled('woodex:expense-recurring'));

// Slice E: the overdue ladder — 7, 14 and 30 days past due, once per rung, consent-gated.
Schedule::command('woodex:invoice-reminders')->dailyAt('10:00')->withoutOverlapping(30)
    ->when(fn (): bool => Automation::enabled('woodex:invoice-reminders'));

// Review requests a few days after a handover was signed (PRJ-007).
Schedule::command('woodex:review-requests')->dailyAt('10:30')
    ->when(fn (): bool => Automation::enabled('woodex:review-requests'));

// P8.5 dormant agents: read-only digests for the owner, OFF until switched on (A0).
Schedule::command('woodex:social-publish')->everyFifteenMinutes();
Schedule::command('woodex:privacy-retention')->dailyAt('02:30');

Schedule::command('woodex:seo-agent')->weeklyOn(0, '08:00')
    ->when(fn (): bool => Automation::enabled('woodex:seo-agent'));

Schedule::command('woodex:social-agent')->weeklyOn(1, '09:30')
    ->when(fn (): bool => Automation::enabled('woodex:social-agent'));

// Backups (spatie/laravel-backup) and housekeeping.
Schedule::command('backup:run --only-db')->dailyAt('02:30');
Schedule::command('backup:run')->weeklyOn(0, '03:00');
// Woodex OS 1: the site is `public/`. Pages published from the OS are new files on this server only, so they
// get their own daily copy inside storage (the deployment's data folder), on top of the weekly full backup.
Schedule::command('woodex:site-backup')->dailyAt('02:45');
Schedule::command('backup:clean')->dailyAt('03:30');
Schedule::command('backup:monitor')->dailyAt('04:00');
Schedule::command('queue:prune-failed --hours=720')->dailyAt('04:15');
Schedule::command('model:prune')->dailyAt('04:30');

// Health (SYS-008/S5) keeps a fortnight of scheduled-task runs; anything older is noise.
Schedule::call(fn () => ScheduledTaskRun::pruneOld())
    ->name('woodex:prune-task-runs')
    ->dailyAt('04:45');
