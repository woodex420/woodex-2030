<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Lead;
use App\Domain\System\Models\AlertRule;
use App\Domain\System\Models\Automation;
use App\Domain\Website\Seo\SearchMetadataAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Bounded read-only probes. Never calls providers, reads credentials into answers or executes fixes. */
final class Diagnostics
{
    public function runtime(User $user): AssistantAnswer
    {
        abort_unless($user->can('system.health'), 403);
        $url = route('admin.system.health');
        $rows = [['label' => 'PHP / Laravel', 'detail' => PHP_VERSION.' / '.app()->version(), 'url' => $url]];
        try {
            $connection = DB::connection();
            $connection->select('SELECT 1');
            $driver = $connection->getDriverName();
            $rows[] = ['label' => 'Database probe', 'detail' => 'Connected: '.$driver.'. SELECT 1 passed.', 'url' => $url];
        } catch (Throwable) {
            // Connection exceptions can contain passwords, DSNs and hosts: never include their messages.
            $rows[] = ['label' => 'Database probe', 'detail' => 'Failed. Ask the owner to review private database configuration; no change made.', 'url' => $url];
        }
        $manifest = public_path('build/manifest.json');
        $build = is_file($manifest) && filesize($manifest) < 1_048_576
            ? json_decode((string) file_get_contents($manifest), true) : null;
        $ready = is_array($build) && isset($build['resources/js/admin.js'], $build['resources/js/auth.js'], $build['resources/css/admin.css']);
        $rows[] = ['label' => 'Frontend build', 'detail' => $ready ? 'Manifest entries present; browser asset loading still needs verification.' : 'Missing/incomplete manifest. Recommend rebuilding reviewed assets in staging.', 'url' => $url];
        $unsafeCookie = (config('session.same_site') === 'none' || config('session.partitioned')) && ! config('session.secure');
        $rows[] = ['label' => 'Sign-in cookies', 'detail' => $unsafeCookie ? 'Review required: cross-site cookies need Secure. Recommend owner-approved configuration review.' : 'No cross-site cookie configuration conflict detected; browser retention is not proven by this check.', 'url' => $url];
        $rows[] = ['label' => 'Debug mode', 'detail' => config('app.debug') ? 'Enabled. Recommend disabling before a production release.' : 'Disabled.', 'url' => $url];
        $rows[] = ['label' => 'Queue worker', 'detail' => 'Worker execution not tested. Check the platform health heartbeat; no job dispatched.', 'url' => $url];

        return new AssistantAnswer('Runtime diagnostics — read-only. Recommendations require owner review; no fixes were executed.', $rows, 'Local PHP/configuration, frontend manifest and SELECT 1. No shell commands or external requests.');
    }

    public function website(User $user): AssistantAnswer
    {
        abort_unless($user->can('website.edit'), 403);
        $audit = app(SearchMetadataAudit::class)->handle();
        $url = route('admin.website.seo.overview', ['audit' => 'metadata']);
        $rows = [['label' => 'Published files inspected', 'detail' => (string) $audit['counts']['files'], 'url' => $url]];
        foreach ([
            'canonical' => ['Canonical review', 'Review preferred URLs; cross-page targets can be intentional.'],
            'social' => ['Social previews', 'Review missing title, description or image metadata in the builder.'],
            'jsonld' => ['Structured data', 'Review JSON-LD syntax/shape. Search eligibility is not certified.'],
            'indexing' => ['Indexing restrictions', 'Review intent. Never remove noindex automatically.'],
            'unreadable' => ['Unreadable files', 'Ask the owner to review file availability and safety limits privately.'],
        ] as $group => [$label, $recommendation]) {
            $rows[] = ['label' => $label, 'detail' => $audit['counts'][$group].' pages. '.$recommendation, 'url' => route('admin.website.seo.overview', ['audit' => 'metadata', 'finding' => $group])];
        }

        return new AssistantAnswer('Website diagnostics — read-only. '.$audit['counts']['flagged'].' pages have review findings. Open SEO → Canonical & social; no pages edited or published.', $rows, 'Local published HTML metadata counts, not a live crawl, Search Console or search-ranking measurement. No URLs fetched; owner review required before fixes.');
    }

    public function enquiries(User $user): AssistantAnswer
    {
        abort_unless($user->can('system.health'), 403);
        $since = now()->subDays(7);
        $count = $user->can('leads.view') ? Lead::query()->visibleTo($user)->where('source', LeadSource::WebsiteForm->value)->where('created_at', '>=', $since)->count() : null;
        $enabled = Automation::query()->where('key', 'woodex:new-lead-alert')->value('is_enabled') ?? Automation::defaultEnabled('woodex:new-lead-alert');
        $overrides = AlertRule::query()->where('event', 'lead.new')->where('is_active', true)->count();
        $alerts = $user->notifications()->where('created_at', '>=', $since)
            ->whereIn('data->kind', ['lead.new', 'lead.returning'])->count();
        $rows = [
            ['label' => 'Visible website-form leads · last 7 days', 'detail' => ($count === null ? 'Not available to your role' : (string) $count).'. A zero count is not proof the live endpoint was tested; imports and chat are separate sources.', 'url' => route('admin.leads.index')],
            ['label' => 'New-enquiry alerts', 'detail' => $enabled ? 'Automation switch on. Routing and transport can still prevent an alert.' : 'Automation switch paused. Lead capture can still succeed without an alert.', 'url' => route('admin.automation.index')],
            ['label' => 'Alert routing overrides', 'detail' => $overrides.' active rules. Rules may intentionally restrict recipients or channels; no routing changed.', 'url' => route('admin.system.alert-rules')],
            ['label' => 'Your in-app lead alerts · last 7 days', 'detail' => $alerts.'. This is only your own notification inbox, not proof of email or Telegram delivery.', 'url' => route('admin.notifications')],
            ['label' => 'Transport distinction', 'detail' => 'Lead alerts run after the HTTP response. Outbound webhook and automation jobs need a running worker. No test enquiry, job, email or provider message was sent.', 'url' => route('admin.system.health')],
        ];
        // Links obey their own screen permissions even when a custom health-only role runs this probe.
        $permissions = ['leads.view', 'automation.manage', 'settings.manage', null, 'system.health'];
        foreach ($rows as $index => &$row) {
            if ($permissions[$index] !== null && ! $user->can($permissions[$index])) {
                $row['url'] = route('admin.system.health');
            }
        }
        unset($row);

        return new AssistantAnswer('Enquiry diagnostics — read-only. Capture, in-app alerts and external delivery are separate checks; no repair or live-site test was executed.', $rows, 'Permission-scoped database counts, your own notification inbox and the alert switch/rule configuration. No customer details, credentials or external requests.');
    }

    public function connections(User $user): AssistantAnswer
    {
        abort_unless($user->can('integrations.manage'), 403);
        $url = route('admin.integrations');
        $telegram = filled(config('services.telegram.bot_token'));
        $google = filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
        $mailer = (string) config('mail.default');
        $mail = in_array($mailer, ['array', 'log'], true) ? 'Non-sending transport configured.' : 'Sending transport configured; delivery not tested.';
        $rows = [
            ['label' => 'Telegram', 'detail' => $telegram ? 'Token configured; identity, owner pairing, connectivity and receipt NOT tested. Rotate exposed tokens before the approved one-report test.' : 'Token not configured. Configure a rotated token privately before testing owner pairing.', 'url' => $url],
            ['label' => 'Google', 'detail' => $google ? 'Credentials configured; authorization and reachability NOT tested.' : 'Credentials not configured. No authorization or reachability test performed.', 'url' => $url],
            ['label' => 'Email', 'detail' => $mail.' No message sent.', 'url' => $url],
            ['label' => 'External AI', 'detail' => 'Not integrated into this Assistant. Approved aggregate policy still needs model, budget and a guarded connector; no provider call made.', 'url' => $url],
        ];

        return new AssistantAnswer('Connection diagnostics — configuration is not proof of connectivity or delivery. No external tests or writes performed.', $rows, 'Allowlisted configuration presence only; no tokens, endpoints, account details or provider responses exposed.');
    }
}
