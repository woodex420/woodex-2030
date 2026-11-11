<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Models\Message;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Models\Lead;
use App\Domain\System\Models\IntegrationLog;
use App\Domain\System\Models\TelegramLink;
use App\Domain\System\Models\WebhookEvent;
use App\Domain\System\Models\WhatsAppTemplate;
use App\Integrations\Connectors\Connector;
use App\Integrations\Connectors\ConnectorManager;
use App\Integrations\Connectors\IntegrationConnection;
use App\Integrations\Google\GoogleConnection;
use App\Integrations\Google\GoogleOAuth;
use App\Integrations\Mcp\IssueMcpToken;
use App\Integrations\Mcp\McpAccessToken;
use App\Integrations\Mcp\RevokeMcpToken;
use App\Integrations\Support\PublicUrlGuard;
use App\Integrations\Telegram\Contracts\TelegramGateway;
use App\Integrations\Telegram\TelegramConfiguration;
use App\Integrations\Telegram\TelegramMessage;
use App\Integrations\Webhooks\OutboundWebhook;
use App\Integrations\Webhooks\WebhookDelivery;
use App\Integrations\Webhooks\WebhookDispatcher;
use App\Integrations\Webhooks\WebhookEvents;
use App\Integrations\WhatsApp\Actions\TestWhatsAppConnection;
use App\Integrations\WhatsApp\WhatsAppCredentials;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\IntegrationSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Integrations hub (SYS-005) with the outbound call log (GLB-018). Secrets live in the server .env
 * (docs/06 §4) and are never shown: cards only say whether each one is configured.
 */
#[Layout('layouts::admin')]
#[Title('Integrations')]
class Integrations extends Component
{
    use InteractsWithAdmin, WithPagination;

    /** Grid groups (INT), in display order. @var array<string, string> */
    private const GROUPS = [
        'communication' => 'Communication',
        'google' => 'Google',
        'social' => 'Social',
        'data' => 'Data',
        'automation' => 'Automation',
    ];

    #[Url(except: '')]
    public string $logIntegration = '';

    #[Url(except: false)]
    public bool $failedOnly = false;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $telegramStatus = null;

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $whatsappStatus = null;

    #[Locked]
    public bool $showTelegramConfiguration = false;

    /** Real connector authorisation, not a roadmap dialog. */
    public bool $showConnectorModal = false;

    #[Locked]
    public string $connectorKey = '';

    /** @var array<string, string> */
    public array $connectorInput = [];

    /** @var array<string, mixed>|null */
    #[Locked]
    public ?array $connectorResult = null;

    public function checkTelegramConfiguration(): void
    {
        abort_unless($this->user()->canUseTelegram() && $this->user()->can('integrations.manage'), 403);
        $this->showTelegramConfiguration = true;
    }

    public function reloadTelegramConfiguration(): void
    {
        abort_unless($this->user()->canUseTelegram() && $this->user()->can('integrations.manage'), 403);

        try {
            $exit = Artisan::call('config:clear');
            clearstatcache(true, app()->getCachedConfigPath());

            if ($exit !== 0 || app()->configurationIsCached()) {
                $this->toast('Configuration cache could not be cleared. Ask hosting support to check cache-file permissions.', 'error');

                return;
            }
        } catch (Throwable) {
            $this->toast('Configuration cache could not be cleared. Ask hosting support to check cache-file permissions.', 'error');

            return;
        }

        // Only a full new request rebuilds services and reloads the active .env. Do not mutate
        // config in this request or call Telegram using the already-resolved gateway singleton.
        session()->flash('status', 'Server configuration cache cleared. Saved secrets were not changed; retry your integration connection.');
        $this->redirectRoute('admin.integrations');
    }

    public function checkTelegramConnection(TelegramGateway $telegram): void
    {
        abort_unless($this->user()->canUseTelegram() && $this->user()->can('integrations.manage'), 403);

        if (! $telegram->isConfigured() || blank(config('services.telegram.webhook_secret'))) {
            $this->telegramSetupRequired();

            return;
        }

        $this->telegramStatus = $telegram->connectionStatus(
            route('webhooks.telegram'),
            (string) config('services.telegram.bot_username'),
        );
        $event = WebhookEvent::query()->where('provider', 'telegram')->latest('id')->first();
        $this->telegramStatus['last_received'] = $event?->created_at?->toIso8601String();
        $this->telegramStatus['last_processed'] = $event?->processed_at?->toIso8601String();
    }

    public string $appSearch = '';

    public string $appCategory = 'all';

    public bool $showGoogleSetup = false;

    public function openGoogleSetup(): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);
        $this->showGoogleSetup = true;
    }

    public bool $showMcpSetup = false;

    public string $bridgeName = '';

    public string $bridgePassword = '';

    public int $bridgeDays = 30;

    /** @var list<string> */
    public array $bridgeAbilities = ['status.read'];

    #[Locked]
    public ?string $newBridgeToken = null;

    /** The provider whose Details panel is open (INT grid). */
    #[Locked]
    public ?string $detailKey = null;

    public bool $showDetails = false;

    public bool $showWebhooks = false;

    public string $webhookName = '';

    public string $webhookUrl = '';

    /** @var list<string> */
    public array $webhookEvents = ['lead.created'];

    /** Shown once, at creation or rotation; hydrate() never restores it. */
    #[Locked]
    public ?string $newWebhookSecret = null;

    public function hydrate(): void
    {
        // Plain secrets are only shown in their creation response, never restored from storage.
        $this->newBridgeToken = null;
        $this->newWebhookSecret = null;
    }

    public function openConnector(string $key, ConnectorManager $manager): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);
        abort_unless($this->user()->hasRole('owner'), 403);
        $connector = $manager->connector($key);

        // An OAuth provider is a browser round trip; a token provider is one field and a real test.
        if ($connector->mode() === 'oauth') {
            $this->redirectRoute('admin.integrations.oauth.start', ['provider' => $key], navigate: true);

            return;
        }

        $this->connectorKey = $key;
        $this->connectorInput = [];
        $this->connectorResult = null;
        $this->resetErrorBag();
        $this->showConnectorModal = true;
    }

    public function connectConnector(ConnectorManager $manager): void
    {
        abort_unless($this->user()->hasRole('owner') && $this->user()->can('integrations.manage'), 403);
        abort_unless($manager->connector($this->connectorKey)->mode() === 'token', 404);

        try {
            $connection = $manager->connectWithToken($this->connectorKey, $this->connectorInput, $this->user());
        } catch (ValidationException $exception) {
            $this->toastValidation($exception);
            $this->addError('connectorInput', (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->showConnectorModal = false;
        $this->connectorInput = [];
        $this->toast('Connected '.$connection->account.' — the provider proved it.');
    }

    public function checkConnector(string $key, ConnectorManager $manager): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);
        $result = $manager->check($key);
        $this->connectorResult = ['key' => $key, ...$result];
        $this->toast($result['ok']
            ? 'Checked now: '.($result['account'] ?? 'connected').'.'
            : 'Check failed: '.$result['error'], $result['ok'] ? 'success' : 'error');
    }

    public function checkAllConnectors(ConnectorManager $manager): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);
        $checked = 0;
        $failed = 0;

        foreach ($manager->connectors() as $connector) {
            if ($manager->connection($connector->key()) === null) {
                continue;
            }

            $checked++;
            $failed += $manager->check($connector->key())['ok'] ? 0 : 1;
        }

        if ($checked === 0) {
            $this->toast('No connector is authorised yet, so there is nothing to check.', 'warning');

            return;
        }

        $this->toast($failed === 0
            ? $checked.' connection'.($checked === 1 ? '' : 's').' answered just now.'
            : $failed.' of '.$checked.' connections did not answer. See the log.', $failed === 0 ? 'success' : 'error');
    }

    public function disconnectConnector(string $key, ConnectorManager $manager): void
    {
        abort_unless($this->user()->hasRole('owner') && $this->user()->can('integrations.manage'), 403);
        abort_unless($manager->disconnect($key), 404);
        $this->connectorResult = null;
        $this->toast('Disconnected. The stored credential was deleted.');
    }

    public function openMcpSetup(): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);
        $this->showMcpSetup = true;
    }

    public function createBridgeKey(IssueMcpToken $issue): void
    {
        try {
            $this->newBridgeToken = $issue->handle($this->user(), $this->bridgePassword,
                $this->bridgeName, $this->bridgeAbilities, $this->bridgeDays);
        } finally {
            $this->bridgePassword = '';
        }
        $this->bridgeName = '';
        $this->toast('Read-only bridge key created. Save it now; it cannot be retrieved later.');
    }

    public function revokeBridgeKey(int $id, RevokeMcpToken $revoke): void
    {
        $revoke->handle($this->user(), $id);
        $this->toast('Bridge key revoked. The adapter can no longer use it.');
    }

    public function openDetails(string $key): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);
        abort_if($key === 'telegram' && ! $this->user()->canUseTelegram(), 403);
        $this->detailKey = $key;
        $this->showDetails = true;
    }

    public function openWebhooks(): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);
        $this->showWebhooks = true;
    }

    public function closeWebhooks(): void
    {
        $this->showWebhooks = false;
    }

    public function createWebhook(): void
    {
        abort_unless($this->user()->hasRole('owner') && $this->user()->can('integrations.manage'), 403);

        $this->validate([
            'webhookName' => ['required', 'string', 'max:80'],
            'webhookUrl' => ['required', 'string', 'max:500'],
            'webhookEvents' => ['required', 'array', 'min:1'],
            'webhookEvents.*' => ['in:'.implode(',', WebhookEvents::keys())],
        ], [], ['webhookName' => 'name', 'webhookUrl' => 'endpoint URL', 'webhookEvents' => 'events']);

        if (! PublicUrlGuard::isSafe(trim($this->webhookUrl))) {
            $this->addError('webhookUrl', 'Use an https URL on a public host. Local and private addresses are refused.');

            return;
        }

        $secret = OutboundWebhook::generateSecret();
        OutboundWebhook::query()->create([
            'name' => trim($this->webhookName),
            'url' => trim($this->webhookUrl),
            'secret' => $secret,
            'events' => array_values($this->webhookEvents),
            'is_active' => true,
            'created_by' => $this->user()->id,
        ]);

        $this->reset('webhookName', 'webhookUrl', 'webhookEvents');
        $this->newWebhookSecret = $secret;
        $this->toast('Webhook added. Save the signing secret now — it will not be shown again.');
    }

    public function toggleWebhook(int $id): void
    {
        abort_unless($this->user()->hasRole('owner') && $this->user()->can('integrations.manage'), 403);

        $webhook = OutboundWebhook::query()->findOrFail($id);
        $webhook->forceFill(['is_active' => ! $webhook->is_active])->save();
        $this->toast($webhook->is_active ? "{$webhook->name} is listening again." : "{$webhook->name} is paused — Woodex will not call it.");
    }

    public function rotateWebhookSecret(int $id): void
    {
        abort_unless($this->user()->hasRole('owner') && $this->user()->can('integrations.manage'), 403);

        $webhook = OutboundWebhook::query()->findOrFail($id);
        $this->newWebhookSecret = OutboundWebhook::generateSecret();
        $webhook->forceFill(['secret' => $this->newWebhookSecret])->save();
        $this->toast("New signing secret for {$webhook->name}. Update it in your app now; the old one stops working immediately.");
    }

    public function deleteWebhook(int $id): void
    {
        abort_unless($this->user()->hasRole('owner') && $this->user()->can('integrations.manage'), 403);

        $webhook = OutboundWebhook::query()->findOrFail($id);
        $name = $webhook->name;
        $webhook->delete();
        $this->toast("{$name} removed. Woodex will not call it again.");
    }

    public function testWebhook(int $id, WebhookDispatcher $dispatcher): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);

        $webhook = OutboundWebhook::query()->findOrFail($id);
        $dispatcher->ping($webhook);
        $this->toast("Test delivery queued for {$webhook->name} — watch the delivery history below.");
    }

    public function mount(): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['logIntegration', 'failedOnly'], true)) {
            $this->resetPage();
        }
    }

    public function testTelegram(TelegramGateway $telegram): void
    {
        abort_unless($this->user()->canUseTelegram() && $this->user()->can('integrations.manage'), 403);
        $chatId = $this->user()->routeNotificationForTelegram();

        if (! $telegram->isConfigured()) {
            $this->telegramSetupRequired();

            return;
        }

        if ($chatId === null) {
            $this->toast('Link your own Telegram first (Security & Telegram).', 'warning');

            return;
        }

        $ok = $telegram->send($chatId, new TelegramMessage('✅ <b>Woodex OS</b> test message. Alerts reach you here.'));
        $this->toast($ok ? 'Test message sent to your Telegram.' : 'Telegram refused the message; see the log below.', $ok ? 'success' : 'error');
    }

    public function setTelegramWebhook(TelegramGateway $telegram): void
    {
        abort_unless($this->user()->canUseTelegram() && $this->user()->can('integrations.manage'), 403);
        $secret = (string) config('services.telegram.webhook_secret');

        if (! $telegram->isConfigured() || $secret === '') {
            $this->telegramSetupRequired();

            return;
        }

        $url = route('webhooks.telegram');
        $ok = str_starts_with($url, 'https://') && $telegram->setWebhook($url, $secret);
        $this->toast($ok ? 'Webhook registered. Now use Check live connection and send /start to verify delivery.' : 'Webhook not set: the site must be on https and reachable.', $ok ? 'success' : 'error');
    }

    /** Does Meta recognise this token and number? (WAP-001) */
    public function testWhatsApp(TestWhatsAppConnection $test): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);

        $this->whatsappStatus = $test->handle();
        $this->toast($this->whatsappStatus['ok']
            ? 'WhatsApp connected: Meta recognises the number.'
            : (string) $this->whatsappStatus['error'], $this->whatsappStatus['ok'] ? 'success' : 'error');
    }

    private function telegramSetupRequired(): void
    {
        if ($this->user()->hasRole('owner')) {
            $this->showTelegramConfiguration = true;
        }

        $this->toast('Telegram credentials are not loaded. The owner can use Check server configuration to find the active settings file and reload cached configuration.', 'warning');
    }

    public function testMail(): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);
        $started = microtime(true);

        try {
            Mail::raw('This is a test email from Woodex OS. Email delivery works.', fn ($m) => $m->to($this->user()->email)->subject('Woodex OS test email'));
            $ok = true;
            $error = null;
        } catch (Throwable $exception) {
            $ok = false;
            $error = $exception->getMessage();
        }

        IntegrationLog::create([
            'integration' => 'mail', 'direction' => 'out', 'action' => 'test', 'ok' => $ok,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'request' => ['mailer' => config('mail.default')], 'error' => $error, 'created_at' => now(),
        ]);

        $this->toast($ok ? "Test email sent to {$this->user()->email}." : 'Email failed: '.$error, $ok ? 'success' : 'error');
    }

    public function testLegacy(): void
    {
        abort_unless($this->user()->can('integrations.manage'), 403);

        try {
            DB::connection('legacy')->getPdo();
            $count = DB::connection('legacy')->table('wx_clients')->count();
            $this->toast("Connected to the v2.1 database ({$count} clients).");
        } catch (Throwable $exception) {
            $this->toast('Cannot reach the v2.1 database: '.str($exception->getMessage())->limit(120), 'error');
        }
    }

    /**
     * One line under the WhatsApp card: which number, how many templates, and how many of them can be sent.
     */
    private function whatsappDetail(WhatsAppCredentials $credentials): string
    {
        if (! $credentials->isConfigured()) {
            return 'Inbox, quotations and follow-ups';
        }

        $parts = [];

        if (in_array($credentials->source(), ['database', 'mixed'], true)) {
            $parts[] = 'connected here';
        }

        try {
            $name = app(IntegrationSettings::class)->whatsapp_verified_name;
        } catch (Throwable) {
            $name = null;
        }

        if (is_string($name) && $name !== '') {
            $parts[] = $name;
        }

        $parts[] = sprintf(
            '%d template%s, %d approved',
            WhatsAppTemplate::query()->count(),
            WhatsAppTemplate::query()->count() === 1 ? '' : 's',
            WhatsAppTemplate::query()->usable()->count(),
        );

        try {
            $rating = app(IntegrationSettings::class)->whatsapp_quality_rating;
        } catch (Throwable) {
            $rating = null;
        }

        if (is_string($rating) && $rating !== '') {
            $parts[] = 'quality '.strtolower($rating);
        }

        $month = $this->monthUsage();

        if ($month['messages'] > 0) {
            $parts[] = sprintf(
                'this month: %d sent%s',
                $month['messages'],
                $month['cost'] > 0 ? ' · est. Rs '.number_format($month['cost']) : '',
            );
        }

        return implode(' · ', $parts);
    }

    /**
     * This month's successfully sent WhatsApp messages and their estimated cost (WAP-008).
     * The daily woodex:whatsapp-health command uses the same numbers for the budget alert.
     *
     * @return array{messages: int, cost: int}
     */
    private function monthUsage(): array
    {
        $counts = Message::query()
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.channel', 'whatsapp')
            ->where('messages.direction', MessageDirection::Out->value)
            ->where('messages.type', '!=', 'note')
            ->where('messages.status', '!=', MessageStatus::Failed->value)
            ->whereBetween('messages.created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->groupBy('messages.type')
            ->selectRaw('messages.type, count(*) as c')
            ->pluck('c', 'messages.type');

        $settings = app(IntegrationSettings::class);

        $cost = ((int) ($counts['text'] ?? 0)) * $settings->whatsapp_text_cost
            + ((int) ($counts['template'] ?? 0)) * $settings->whatsapp_template_cost;

        return ['messages' => (int) $counts->sum(), 'cost' => $cost];
    }

    /**
     * Every integration exactly once, normalised for the grid: one honest state, one action set.
     * States are measurements, never saved flags — connectors carry their last probe, server
     * channels read the outbound log, and "configured" alone never reads as "connected".
     *
     * @param  array<string, IntegrationConnection|null>  $connections
     * @return list<array{key: string, name: string, icon: string, group: string, kind: string, benefit: string, detail: string, state: string, state_note: string, account: ?string, scopes: list<string>, missing: ?string, help: ?string, last_error: ?string, log_key: ?string, lines: list<array{0: string, 1: string}>}>
     */
    private function providers(ConnectorManager $manager, WhatsAppCredentials $credentials, array $connections, ?GoogleConnection $googleConnection, int $activeBridgeCount): array
    {
        $lastRow = fn (string $key, bool $ok): ?IntegrationLog => IntegrationLog::query()->where('integration', $key)->where('ok', $ok)->latest('id')->first(['created_at', 'error']);

        $server = function (array $entry, bool $configured, ?string $logKey, string $checkNote = 'Configured · nothing recorded yet') use ($lastRow): array {
            $ok = $logKey !== null ? $lastRow($logKey, true) : null;
            $error = $logKey !== null ? $lastRow($logKey, false) : null;
            [$state, $note] = $this->serverState($configured, $ok, $error);

            return [...$entry,
                'kind' => 'server', 'state' => $state, 'state_note' => $state === 'check' && $ok === null ? $checkNote : $note,
                'account' => null, 'scopes' => [], 'missing' => null,
                'last_error' => $state === 'error' ? $error?->error : null, 'log_key' => $logKey,
            ];
        };

        $providers = [];

        // Communication — server channels whose secrets live in the server .env.
        $providers[] = $server([
            'key' => 'telegram', 'name' => 'Telegram bot', 'icon' => 'send', 'group' => 'communication',
            'benefit' => 'Owner-only alerts, commands and the daily summary in Telegram.',
            'detail' => TelegramLink::query()->usable()->count().' owner linked · webhook secret '.(filled(config('services.telegram.webhook_secret')) ? 'set' : 'missing'),
            'help' => 'Create the bot with @BotFather, put TELEGRAM_BOT_TOKEN and a long TELEGRAM_WEBHOOK_SECRET you invent into the server .env, then press Connect webhook.',
            'lines' => [['Credentials', 'Server .env — TELEGRAM_BOT_TOKEN · TELEGRAM_WEBHOOK_SECRET']],
        ], filled(config('services.telegram.bot_token')), 'telegram');

        $providers[] = $server([
            'key' => 'whatsapp', 'name' => 'WhatsApp Cloud API', 'icon' => 'whatsapp', 'group' => 'communication',
            'benefit' => 'Inbox, quotation links and follow-ups on WhatsApp.',
            'detail' => $this->whatsappDetail($credentials),
            'help' => 'Meta for Developers → your app → WhatsApp → API setup gives the token and phone number ID. Saving them in Settings → WhatsApp is easiest; the .env values are the fallback.',
            'lines' => [['Credentials', in_array($credentials->source(), ['database', 'mixed'], true) ? 'Saved in Settings → WhatsApp (encrypted)' : 'Server .env — WHATSAPP_TOKEN · WHATSAPP_PHONE_NUMBER_ID']],
        ], $credentials->isConfigured(), 'whatsapp');

        $providers[] = $server([
            'key' => 'mail', 'name' => 'Email (SMTP)', 'icon' => 'mail', 'group' => 'communication',
            'benefit' => 'Quotation emails, receipts and login alerts.',
            'detail' => 'Mailer: '.config('mail.default').' · from '.config('mail.from.address'),
            'help' => 'Any SMTP account works — set MAIL_MAILER=smtp with host, port, username and password in the server .env.',
            'lines' => [['Credentials', 'Server .env — MAIL_* keys']],
        ], ! in_array(config('mail.default'), ['log', 'array'], true), 'mail');

        // Google — staff identity, plus the Workspace apps its scopes unlock.
        $providers[] = [
            'key' => 'google_account', 'name' => 'Google account', 'icon' => 'globe', 'group' => 'google', 'kind' => 'google',
            'benefit' => 'Staff sign-in with Google — identity only, no mailbox access.',
            'detail' => $googleConnection->email ?? 'Authorize a company account securely with Google. Basic identity access only.',
            'state' => $googleConnection?->status === 'active' ? 'connected' : ($googleConnection ? 'error' : 'off'),
            'state_note' => $googleConnection?->status === 'active' ? 'Connected · identity only' : ($googleConnection ? 'Action required' : (GoogleOAuth::configured() ? 'Not connected' : 'Setup required')),
            'account' => $googleConnection?->email, 'scopes' => [], 'missing' => null, 'last_error' => null, 'log_key' => null,
            'help' => 'Google Cloud console → OAuth client (GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in the server .env), then Connect.',
            'lines' => array_values(array_filter([
                ['Email', $googleConnection?->email],
                ['Status', $googleConnection?->status],
                ['Last checked', $googleConnection?->last_checked_at?->diffForHumans()],
            ], fn (array $line): bool => filled($line[1] ?? null))),
        ];

        $workspace = $connections['google_workspace'] ?? null;
        $granted = $workspace instanceof IntegrationConnection ? ($workspace->scopes ?? []) : [];
        $workspaceConnector = $manager->connector('google_workspace');
        $fresh = $workspace?->last_checked_at !== null && $workspace->last_checked_at->gt(now()->subMinutes(15));

        foreach ([
            ['gmail', 'Gmail', 'mail', 'https://www.googleapis.com/auth/gmail.readonly', 'Company Gmail, read-only — client context for the AI bridge.'],
            ['google_drive', 'Google Drive', 'folder', 'https://www.googleapis.com/auth/drive.metadata.readonly', 'Company Drive files, read-only — for the AI bridge.'],
        ] as [$appKey, $appName, $appIcon, $scope, $benefit]) {
            $hasScope = in_array($scope, $granted, true);
            $providers[] = [
                'key' => $appKey, 'name' => $appName, 'icon' => $appIcon, 'group' => 'google', 'kind' => 'workspace_app',
                'benefit' => $benefit,
                'detail' => $hasScope ? 'Read scope granted' : 'Part of the Google Workspace connection.',
                'state' => $workspace?->status === 'error' ? 'error' : ($hasScope ? ($fresh ? 'connected' : 'check') : 'off'),
                'state_note' => $workspace?->status === 'error' ? 'Workspace check failed' : ($hasScope ? 'Read scope granted' : 'Connect Google Workspace'),
                'account' => $workspace?->account, 'scopes' => $granted,
                'missing' => $workspaceConnector->missingRequirement(),
                'help' => $workspaceConnector->help(),
                'log_key' => 'google_workspace', 'last_error' => $workspace?->last_error,
                'lines' => array_values(array_filter([
                    ['Connection', 'Shared Google Workspace authorisation — Gmail and Drive connect and disconnect together'],
                    ['Account', $workspace?->account],
                    ['This app needs', $scope],
                    ['Last checked', $workspace?->last_checked_at?->diffForHumans()],
                ], fn (array $line): bool => filled($line[1] ?? null))),
            ];
        }

        // Social — real connectors only; a network earns a card the day it can be proved.
        $providers[] = $this->connectorProvider($manager->connector('facebook_page'), $connections['facebook_page'] ?? null, 'social');

        // Data.
        $webSources = array_map(fn (LeadSource $source): string => $source->value, LeadSource::publicSources());
        $websiteLast = Lead::query()->whereIn('source', $webSources)->latest('id')->value('created_at');
        $websiteConfigured = config('woodex.public_leads.allowed_origins') !== [];
        $providers[] = [
            'key' => 'website', 'name' => 'Website lead forms', 'icon' => 'globe', 'group' => 'data', 'kind' => 'server',
            'benefit' => 'Enquiries from woodex.com.pk land straight in Leads.',
            'detail' => 'POST '.url('/api/public/leads').' · bridge key '.(filled(config('woodex.public_leads.bridge_key')) ? 'set' : 'not set'),
            'state' => ! $websiteConfigured ? 'off' : ($websiteLast !== null ? 'connected' : 'check'),
            'state_note' => $websiteLast !== null ? 'Last enquiry '.$websiteLast->diffForHumans() : 'Configured · no enquiries yet',
            'account' => null, 'scopes' => [], 'missing' => null, 'last_error' => null, 'log_key' => null,
            'help' => 'Forms POST to the endpoint from origins in PUBLIC_LEADS_ALLOWED_ORIGINS; server-to-server calls send the X-Woodex-Key bridge header (PUBLIC_LEADS_BRIDGE_KEY).',
            'lines' => [['Credentials', 'Server .env — PUBLIC_LEADS_ALLOWED_ORIGINS · PUBLIC_LEADS_BRIDGE_KEY']],
        ];

        $providers[] = $server([
            'key' => 'turnstile', 'name' => 'Cloudflare Turnstile', 'icon' => 'shield', 'group' => 'data',
            'benefit' => 'Invisible spam protection on the public forms.',
            'detail' => 'Spam protection on public forms',
            'help' => 'Cloudflare dashboard → Turnstile → add the site; copy the site key and secret into the server .env.',
            'lines' => [['Credentials', 'Server .env — TURNSTILE_SITE_KEY · TURNSTILE_SECRET_KEY']],
        ], filled(config('services.turnstile.secret_key')), null, 'Configured · verified on each form submit');

        $providers[] = $this->connectorProvider($manager->connector('github'), $connections['github'] ?? null, 'data');

        $providers[] = $server([
            'key' => 'legacy', 'name' => 'Woodex v2.1 database', 'icon' => 'database', 'group' => 'data',
            'benefit' => 'Read-only window into the old admin for the data import.',
            'detail' => 'Read-only source for the data import',
            'help' => 'A read-only MySQL user for the v2.1 database, in the server .env (legacy connection). Used only by the data import.',
            'lines' => [['Credentials', 'Server .env — legacy database connection']],
        ], filled(config('database.connections.legacy.database')), null, 'Configured · press Test to prove it');

        // Automation.
        $webhookTotal = OutboundWebhook::query()->count();
        $webhookActive = OutboundWebhook::query()->active()->count();
        $lastDelivery = WebhookDelivery::query()->latest('id')->first(['status', 'created_at', 'error']);
        $providers[] = [
            'key' => 'webhooks', 'name' => 'Webhooks', 'icon' => 'zap', 'group' => 'automation', 'kind' => 'webhooks',
            'benefit' => 'Tell Zapier, Make or your own app the moment a lead, quotation or payment lands.',
            'detail' => $webhookTotal === 0 ? 'No endpoints yet — add your first app.' : $webhookActive.' of '.$webhookTotal.' endpoints listening',
            'state' => $webhookActive === 0 ? 'off' : ($lastDelivery?->status === 'failed' ? 'error' : 'check'),
            'state_label' => $webhookActive === 0 ? 'Endpoints paused / absent' : 'Endpoints configured',
            'state_note' => $lastDelivery === null ? 'Nothing delivered yet; configuration is not proof of connectivity.' : 'Most recent delivery across endpoints '.$lastDelivery->created_at?->diffForHumans().' · '.$lastDelivery->status,
            'account' => null, 'scopes' => [], 'missing' => null,
            'last_error' => $lastDelivery !== null && $lastDelivery->status === 'failed' ? $lastDelivery->error : null,
            'log_key' => 'webhooks',
            'help' => 'Paste the https URL your app gives you (Zapier: Webhooks by Zapier → Catch Hook; Make: Webhooks → Custom webhook). Every delivery is signed — verify the X-Woodex-Signature header (HMAC-SHA256 of the body) if your app supports it.',
            'lines' => [
                ['Signing', 'HMAC-SHA256 in the X-Woodex-Signature header'],
                ['Events', implode(' · ', WebhookEvents::keys())],
            ],
        ];

        $providers[] = $this->connectorProvider($manager->connector('custom_api'), $connections['custom_api'] ?? null, 'automation');

        $providers[] = [
            'key' => 'mcp', 'name' => 'AI bridge (MCP)', 'icon' => 'command', 'group' => 'automation', 'kind' => 'mcp',
            'benefit' => 'Claude, Cursor and other MCP clients read pages, health, leads and quotes — read-only, expiring keys.',
            'detail' => $activeBridgeCount.' active '.($activeBridgeCount === 1 ? 'key' : 'keys').' · '.url('/api/mcp'),
            'state' => $activeBridgeCount > 0 ? 'check' : 'off',
            'state_note' => $activeBridgeCount > 0 ? 'Active keys issued · not a client connectivity test' : 'No active keys',
            'account' => null, 'scopes' => [], 'missing' => null, 'last_error' => null, 'log_key' => null, 'help' => null,
            'lines' => [['Endpoint', url('/api/mcp')], ['Access', 'Read-only · Bearer key · JSON-RPC over HTTPS']],
        ];

        return $providers;
    }

    /** @return array{0: string, 1: string} */
    private function serverState(bool $configured, ?IntegrationLog $lastOk, ?IntegrationLog $lastError): array
    {
        if (! $configured) {
            return ['off', 'Not connected'];
        }

        if ($lastError !== null && ($lastOk === null || $lastError->created_at->gte($lastOk->created_at))) {
            return ['error', 'Error '.$lastError->created_at->diffForHumans()];
        }

        if ($lastOk !== null) {
            return [$lastOk->created_at->gt(now()->subMinutes(15)) ? 'connected' : 'check', 'Last OK '.$lastOk->created_at->diffForHumans()];
        }

        return ['check', 'Configured · nothing recorded yet'];
    }

    /** @return array<string, mixed> */
    private function connectorProvider(Connector $connector, ?IntegrationConnection $connection, string $group): array
    {
        $state = $connection === null ? 'off' : ($connection->status === 'error' ? 'error'
            : ($connection->last_checked_at !== null && $connection->last_checked_at->gt(now()->subMinutes(15)) ? 'connected' : 'check'));

        return [
            'key' => $connector->key(), 'name' => $connector->name(), 'icon' => $connector->icon(),
            'group' => $group, 'kind' => 'connector',
            'benefit' => $connector->detail(), 'detail' => $connector->detail(),
            'state' => $state,
            'state_note' => $connection === null
                ? ($connector->missingRequirement() ?? 'Not connected')
                : ($state === 'error' ? 'Last check failed' : 'Checked '.($connection->last_checked_at?->diffForHumans() ?? 'just now')),
            'account' => $connection?->account,
            'scopes' => $connection->scopes ?? [],
            'missing' => $connector->missingRequirement(),
            'help' => $connector->help(),
            'last_error' => $connection?->last_error,
            'log_key' => $connector->key(),
            'lines' => array_values(array_filter([
                ['Account', $connection?->account],
                ['Connected', $connection?->connected_at?->format('j M Y')],
                ['Last checked', $connection?->last_checked_at?->diffForHumans()],
                ['Last error', $connection?->last_error],
                ['Credentials', 'Encrypted at rest · '.($connection->meta['credential_hint'] ?? 'hidden').' — Remove deletes them'],
            ], fn (array $line): bool => filled($line[1] ?? null))),
        ];
    }

    public function render(): View
    {
        $manager = app(ConnectorManager::class);
        $credentials = app(WhatsAppCredentials::class);
        $connections = [];
        $connectors = [];
        foreach ($manager->connectors() as $connector) {
            $connection = $manager->connection($connector->key());
            $connections[$connector->key()] = $connection;
            $connectors[] = ['key' => $connector->key(), 'name' => $connector->name(),
                'fields' => $connector->fields(), 'help' => $connector->help()];
        }
        $googleConnection = GoogleConnection::query()->find(1);
        $activeBridgeCount = McpAccessToken::query()->whereNull('revoked_at')->where('expires_at', '>', now())->count();
        $providers = $this->providers($manager, $credentials, $connections, $googleConnection, $activeBridgeCount);
        $telegramAllowed = $this->user()->canUseTelegram();
        $providers = array_values(array_filter($providers, fn (array $provider): bool => $provider['key'] !== 'telegram' || $telegramAllowed));
        $detail = collect($providers)->firstWhere('key', $this->detailKey);
        $filtered = array_values(array_filter($providers, fn (array $provider): bool => ($this->appCategory === 'all' || $provider['group'] === $this->appCategory)
            && ($this->appSearch === '' || str_contains(strtolower($provider['name'].' '.$provider['benefit']), strtolower($this->appSearch)))));

        return view('livewire.admin.system.integrations', [
            'providers' => $filtered,
            'groups' => self::GROUPS,
            'detail' => $detail,
            'connectorForm' => collect($connectors)->firstWhere('key', $this->connectorKey),
            'googleConnection' => $googleConnection,
            'googleConfigured' => GoogleOAuth::configured(),
            'googleLoginEnabled' => GoogleOAuth::loginEnabled(),
            'bridgeKeys' => $this->user()->hasRole('owner')
                ? McpAccessToken::query()->with('user:id,name')->latest('id')->limit(30)->get() : collect(),
            'activeBridgeCount' => $activeBridgeCount,
            'telegramConfiguration' => $this->showTelegramConfiguration && $telegramAllowed
                ? app(TelegramConfiguration::class)->inspect() : null,
            'webhooks' => $this->showWebhooks ? OutboundWebhook::query()->withCount('deliveries')->latest('id')->get() : collect(),
            'deliveries' => $this->showWebhooks ? WebhookDelivery::query()->with('webhook')->latest('id')->limit(30)->get() : collect(),
            'eventOptions' => WebhookEvents::options(),
            'logs' => IntegrationLog::query()->when(! $telegramAllowed, fn ($query) => $query->where('integration', '!=', 'telegram'))
                ->when($this->logIntegration !== '', fn ($q) => $q->where('integration', $this->logIntegration))
                ->when($this->failedOnly, fn ($q) => $q->where('ok', false))->latest('id')->paginate(20),
            'integrations' => IntegrationLog::query()->when(! $telegramAllowed, fn ($query) => $query->where('integration', '!=', 'telegram'))->distinct()->orderBy('integration')->pluck('integration'),
        ]);
    }
}
