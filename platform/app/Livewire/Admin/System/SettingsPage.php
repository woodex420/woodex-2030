<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Domain\Bot\Actions\SyncBotKnowledge;
use App\Domain\Bot\Models\BotKnowledge;
use App\Integrations\WhatsApp\Actions\TestWhatsAppConnection;
use App\Integrations\WhatsApp\WhatsAppCredentials;
use App\Livewire\Admin\Crm\ContactsIndex;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Settings\BotSettings;
use App\Settings\BusinessSettings;
use App\Settings\IntegrationSettings;
use App\Settings\NotificationSettings;
use App\Settings\SalesSettings;
use App\Support\Phone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Settings (SYS-001): business identity and hours, sales rules, notifications. Values feed quotations,
 * the website, SLA timers and alerts, so the owner changes them here instead of asking a developer.
 */
#[Layout('layouts::admin')]
#[Title('Settings')]
class SettingsPage extends Component
{
    use InteractsWithAdmin;

    public const SOCIAL_NETWORKS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'pinterest' => 'Pinterest'];

    public const DISCOUNT_ROLES = ['sales' => 'Sales', 'designer_pm' => 'Designer / PM', 'sales_manager' => 'Sales manager', 'admin' => 'Admin', 'owner' => 'Owner'];

    #[Url(except: 'business')]
    public string $tab = 'business';

    /** @var array<string, mixed> */
    public array $business = [];

    /** @var array<string, mixed> */
    public array $sales = [];

    /** @var array<string, mixed> */
    public array $notifications = [];

    /**
     * WhatsApp credentials (WAP-001). The three secrets are deliberately never loaded into this array:
     * a Livewire property travels to the browser, and a key that has been to the browser is no longer secret.
     * The boxes start empty, show a masked hint of what is saved, and leaving one empty keeps what is there.
     *
     * @var array<string, mixed>
     */
    public array $whatsapp = [];

    /**
     * BOT-002…010: the website assistant's knobs, shown on its own tab.
     *
     * @var array<string, mixed>
     */
    public array $bot = [];

    /** A manual knowledge entry being typed (BOT-003). */
    public string $botKnowledgeTitle = '';

    public string $botKnowledgeBody = '';

    /** @var array<string, mixed>|null */
    public ?array $whatsappStatus = null;

    public function mount(BusinessSettings $business, SalesSettings $sales, NotificationSettings $notifications, IntegrationSettings $integrations): void
    {
        abort_unless($this->user()->can('settings.manage') || $this->user()->can('integrations.manage'), 403);

        $this->business = [
            'legal_name' => $business->legal_name,
            'brand_name' => $business->brand_name,
            'furniture_brand_name' => $business->furniture_brand_name,
            'tagline' => $business->tagline,
            'address' => $business->address,
            'city' => $business->city,
            'phone' => Phone::display($business->phone),
            'whatsapp' => Phone::display($business->whatsapp),
            'email' => $business->email,
            'website' => $business->website,
            'ntn' => $business->ntn,
            'founded_year' => $business->founded_year,
            'opens_at' => $business->opens_at,
            'closes_at' => $business->closes_at,
            'working_days' => array_map('strval', $business->working_days),
            'social_links' => array_merge(array_fill_keys(array_keys(self::SOCIAL_NETWORKS), ''), $business->social_links),
            'holidays' => collect($business->holidays)->map(fn (array $h): string => trim($h['date'].' '.$h['name']))->implode("\n"),
        ];

        $limits = [];
        foreach (self::DISCOUNT_ROLES as $role => $label) {
            $limits[$role] = self::bpToPercent($sales->discount_limits_bp[$role] ?? 0);
        }

        $this->sales = [
            'quote_validity_days' => $sales->quote_validity_days,
            'quote_follow_ups_enabled' => $sales->quote_follow_ups_enabled,
            'quote_follow_up_days' => $sales->quote_follow_up_days,
            'quote_expiry_warning_days' => $sales->quote_expiry_warning_days,
            'round_totals_to_rupee' => $sales->round_totals_to_rupee,
            'lakh_grouping' => $sales->lakh_grouping,
            'sla_first_response_minutes' => $sales->sla_first_response_minutes,
            'invoice_due_days' => $sales->invoice_due_days,
            'discount_limits' => $limits,
            'min_margin' => $sales->min_margin_bp === null ? '' : self::bpToPercent($sales->min_margin_bp),
            'lost_reasons' => implode("\n", $sales->lost_reasons),
            'budget_bands' => collect($sales->budget_bands)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
            'signatories' => array_values($sales->signatories),
            'execution_terms' => $sales->execution_terms,
            'design_terms' => $sales->design_terms,
        ];

        $bot = app(BotSettings::class);
        $this->bot = [
            'enabled' => (bool) $bot->enabled,
            'provider' => (string) $bot->provider,
            'model' => (string) $bot->model,
            'system_prompt' => (string) $bot->system_prompt,
            'daily_token_cap' => (string) ($bot->daily_token_cap ?: 0),
            'conversation_turn_limit' => (string) ($bot->conversation_turn_limit ?: 0),
            'outside_hours_only' => (bool) $bot->outside_hours_only,
        ];

        $this->whatsapp = [
            'waba_id' => (string) ($integrations->whatsapp_waba_id ?? ''),
            'phone_number_id' => (string) ($integrations->whatsapp_phone_number_id ?? ''),
            'api_version' => (string) ($integrations->whatsapp_api_version ?: 'v23.0'),
            'text_cost' => (string) ($integrations->whatsapp_text_cost ?: 0),
            'template_cost' => (string) ($integrations->whatsapp_template_cost ?: 0),
            'monthly_budget' => (string) ($integrations->whatsapp_monthly_budget ?: 0),
            'token' => '',
            'app_secret' => '',
            'verify_token' => '',
            'probe_number' => '',
        ];

        $this->notifications = [
            'telegram_enabled' => $notifications->telegram_enabled,
            'chat_alert_sound' => $notifications->chat_alert_sound,
            'quiet_hours_start' => $notifications->quiet_hours_start,
            'quiet_hours_end' => $notifications->quiet_hours_end,
            'daily_digest_time' => $notifications->daily_digest_time,
            'unassigned_lead_roles' => $notifications->unassigned_lead_roles,
            'sla_final_roles' => $notifications->sla_final_roles,
        ];
    }

    public function saveBusiness(BusinessSettings $settings): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $data = $this->validate([
            'business.legal_name' => ['required', 'string', 'max:160'],
            'business.brand_name' => ['required', 'string', 'max:160'],
            'business.furniture_brand_name' => ['required', 'string', 'max:160'],
            'business.tagline' => ['required', 'string', 'max:255'],
            'business.address' => ['required', 'string', 'max:255'],
            'business.city' => ['required', 'string', 'max:80'],
            'business.phone' => ['required', 'string', 'max:30', ContactsIndex::phoneRule()],
            'business.whatsapp' => ['required', 'string', 'max:30', ContactsIndex::phoneRule()],
            'business.email' => ['required', 'email', 'max:160'],
            'business.website' => ['required', 'url', 'max:191'],
            'business.ntn' => ['nullable', 'string', 'max:30'],
            'business.founded_year' => ['required', 'integer', 'between:1950,2100'],
            'business.opens_at' => ['required', 'date_format:H:i'],
            'business.closes_at' => ['required', 'date_format:H:i', 'after:business.opens_at'],
            'business.working_days' => ['required', 'array', 'min:1'],
            'business.working_days.*' => ['integer', 'between:1,7'],
            'business.social_links.*' => ['nullable', 'url', 'max:255'],
            'business.holidays' => ['nullable', 'string', 'max:5000'],
        ], attributes: [
            'business.phone' => 'phone', 'business.whatsapp' => 'WhatsApp number', 'business.closes_at' => 'closing time',
            'business.opens_at' => 'opening time', 'business.working_days' => 'working days', 'business.social_links.*' => 'link',
        ])['business'];

        $settings->legal_name = trim($data['legal_name']);
        $settings->brand_name = trim($data['brand_name']);
        $settings->furniture_brand_name = trim($data['furniture_brand_name']);
        $settings->tagline = trim($data['tagline']);
        $settings->address = trim($data['address']);
        $settings->city = trim($data['city']);
        $settings->phone = (string) Phone::normalize($data['phone']);
        $settings->whatsapp = (string) Phone::normalize($data['whatsapp']);
        $settings->email = Str::lower(trim($data['email']));
        $settings->website = rtrim(trim($data['website']), '/');
        $settings->ntn = filled($data['ntn'] ?? null) ? trim((string) $data['ntn']) : null;
        $settings->founded_year = (int) $data['founded_year'];
        $settings->opens_at = $data['opens_at'];
        $settings->closes_at = $data['closes_at'];
        $days = array_map('intval', $data['working_days']);
        sort($days);
        $settings->working_days = array_values(array_unique($days));
        $settings->social_links = array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', $data['social_links'] ?? []));

        $holidays = self::parseHolidays((string) ($data['holidays'] ?? ''));
        if ($holidays === null) {
            $this->addError('business.holidays', 'Write one holiday per line as YYYY-MM-DD and a name, e.g. 2027-03-23 Pakistan Day.');

            return;
        }
        $settings->holidays = $holidays;
        $settings->save();

        $this->business['phone'] = Phone::display($settings->phone);
        $this->business['whatsapp'] = Phone::display($settings->whatsapp);
        $this->toast('Business settings saved.');
    }

    public function saveSales(SalesSettings $settings): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $data = $this->validate([
            'sales.quote_validity_days' => ['required', 'integer', 'between:1,365'],
            'sales.quote_follow_up_days' => ['required', 'integer', 'between:1,60'],
            'sales.quote_expiry_warning_days' => ['required', 'integer', 'between:1,60'],
            'sales.round_totals_to_rupee' => ['boolean'],
            'sales.lakh_grouping' => ['boolean'],
            'sales.sla_first_response_minutes' => ['required', 'integer', 'between:5,1440'],
            'sales.invoice_due_days' => ['required', 'integer', 'between:0,365'],
            'sales.discount_limits' => ['required', 'array'],
            'sales.discount_limits.*' => ['required', 'numeric', 'between:0,100'],
            'sales.min_margin' => ['nullable', 'numeric', 'between:0,100'],
            'sales.lost_reasons' => ['required', 'string', 'max:3000'],
            'sales.budget_bands' => ['required', 'array', 'min:1', 'max:12'],
            'sales.budget_bands.*.key' => ['required', 'string', 'max:30', 'regex:/^[a-z0-9-]+$/', 'distinct'],
            'sales.budget_bands.*.label' => ['required', 'string', 'max:60'],
            'sales.signatories' => ['array', 'max:4'],
            'sales.signatories.*.name' => ['required', 'string', 'max:80'],
            'sales.signatories.*.title' => ['required', 'string', 'max:80'],
            'sales.execution_terms' => ['required', 'string', 'max:5000'],
            'sales.design_terms' => ['required', 'string', 'max:5000'],
        ], [
            'sales.budget_bands.*.key.regex' => 'Keys use lowercase letters, numbers and dashes only (e.g. 3m-7m).',
        ], [
            'sales.sla_first_response_minutes' => 'response target', 'sales.discount_limits.*' => 'discount limit',
            'sales.budget_bands.*.key' => 'key', 'sales.budget_bands.*.label' => 'label',
            'sales.signatories.*.name' => 'name', 'sales.signatories.*.title' => 'title',
        ])['sales'];

        $reasons = collect(preg_split('/\R/', (string) $data['lost_reasons']) ?: [])->map(fn ($r) => trim((string) $r))->filter()->unique()->values()->all();

        if ($reasons === []) {
            $this->addError('sales.lost_reasons', 'Add at least one lost reason.');

            return;
        }

        $settings->quote_validity_days = (int) $data['quote_validity_days'];
        $settings->quote_follow_ups_enabled = (bool) ($data['quote_follow_ups_enabled'] ?? false);
        $settings->quote_follow_up_days = max(1, (int) $data['quote_follow_up_days']);
        $settings->quote_expiry_warning_days = max(1, (int) $data['quote_expiry_warning_days']);
        $settings->round_totals_to_rupee = (bool) ($data['round_totals_to_rupee'] ?? false);
        $settings->lakh_grouping = (bool) ($data['lakh_grouping'] ?? false);
        $settings->sla_first_response_minutes = (int) $data['sla_first_response_minutes'];
        $settings->invoice_due_days = (int) $data['invoice_due_days'];
        $settings->discount_limits_bp = collect((array) $data['discount_limits'])->map(fn ($p) => self::percentToBp($p))->all();
        $settings->min_margin_bp = filled($data['min_margin'] ?? null) ? self::percentToBp($data['min_margin']) : null;
        $settings->lost_reasons = $reasons;
        $settings->budget_bands = collect((array) $data['budget_bands'])->mapWithKeys(fn ($b) => [trim($b['key']) => trim($b['label'])])->all();
        $settings->signatories = array_values(array_map(fn ($s) => ['name' => trim($s['name']), 'title' => trim($s['title'])], $data['signatories'] ?? []));
        $settings->execution_terms = trim($data['execution_terms']);
        $settings->design_terms = trim($data['design_terms']);
        $settings->save();

        $this->sales['lost_reasons'] = implode("\n", $reasons);
        $this->toast('Sales settings saved.');
    }

    public function saveNotifications(NotificationSettings $settings): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $data = $this->validate([
            'notifications.telegram_enabled' => ['boolean'],
            'notifications.chat_alert_sound' => ['boolean'],
            'notifications.quiet_hours_start' => ['required', 'date_format:H:i'],
            'notifications.quiet_hours_end' => ['required', 'date_format:H:i'],
            'notifications.daily_digest_time' => ['required', 'date_format:H:i'],
            'notifications.unassigned_lead_roles' => ['required', 'array', 'min:1'],
            'notifications.unassigned_lead_roles.*' => [Rule::in(array_keys(UsersIndex::ROLES))],
            'notifications.sla_final_roles' => ['required', 'array', 'min:1'],
            'notifications.sla_final_roles.*' => [Rule::in(array_keys(UsersIndex::ROLES))],
        ], attributes: ['notifications.unassigned_lead_roles' => 'escalation roles', 'notifications.sla_final_roles' => 'final escalation roles'])['notifications'];

        $settings->telegram_enabled = (bool) ($data['telegram_enabled'] ?? false);
        $settings->chat_alert_sound = (bool) ($data['chat_alert_sound'] ?? false);
        $settings->quiet_hours_start = $data['quiet_hours_start'];
        $settings->quiet_hours_end = $data['quiet_hours_end'];
        $settings->daily_digest_time = $data['daily_digest_time'];
        $settings->unassigned_lead_roles = array_values($data['unassigned_lead_roles']);
        $settings->sla_final_roles = array_values($data['sla_final_roles']);
        $settings->save();

        $this->toast('Notification settings saved.');
    }

    public function addBand(): void
    {
        $this->sales['budget_bands'][] = ['key' => '', 'label' => ''];
    }

    public function removeBand(int $index): void
    {
        unset($this->sales['budget_bands'][$index]);
        $this->sales['budget_bands'] = array_values($this->sales['budget_bands']);
    }

    public function addSignatory(): void
    {
        $this->sales['signatories'][] = ['name' => '', 'title' => ''];
    }

    public function removeSignatory(int $index): void
    {
        unset($this->sales['signatories'][$index]);
        $this->sales['signatories'] = array_values($this->sales['signatories']);
    }

    /**
     * Save the WhatsApp credentials (WAP-001). An empty secret box keeps the saved value, so the studio can
     * change the phone number id without retyping the token — and so the token is never sent to the browser.
     */
    public function saveWhatsApp(IntegrationSettings $settings): void
    {
        abort_unless($this->user()->can('settings.manage') || $this->user()->can('integrations.manage'), 403);

        $data = $this->validate([
            'whatsapp.waba_id' => ['nullable', 'string', 'max:64', 'regex:/^\d*$/'],
            'whatsapp.phone_number_id' => ['nullable', 'string', 'max:64', 'regex:/^\d*$/'],
            'whatsapp.api_version' => ['required', 'string', 'regex:/^v\d+\.\d+$/'],
            'whatsapp.token' => ['nullable', 'string', 'max:512'],
            'whatsapp.app_secret' => ['nullable', 'string', 'max:256'],
            'whatsapp.verify_token' => ['nullable', 'string', 'max:128'],
            'whatsapp.text_cost' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'whatsapp.template_cost' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'whatsapp.monthly_budget' => ['nullable', 'integer', 'min:0', 'max:10000000'],
        ], [
            'whatsapp.waba_id.regex' => 'The business account id is a number, taken from Meta → WhatsApp → API Setup.',
            'whatsapp.phone_number_id.regex' => 'The phone number id is a number, taken from Meta → WhatsApp → API Setup.',
            'whatsapp.api_version.regex' => 'Write the version as Meta does, for example v23.0.',
        ]);

        $settings->whatsapp_waba_id = blank($data['whatsapp']['waba_id'] ?? null) ? null : trim((string) $data['whatsapp']['waba_id']);
        $settings->whatsapp_phone_number_id = blank($data['whatsapp']['phone_number_id'] ?? null) ? null : trim((string) $data['whatsapp']['phone_number_id']);
        $settings->whatsapp_api_version = trim((string) $data['whatsapp']['api_version']);

        // WAP-008: the cost meter's rate table and budget. Zero means "count only, no rupees".
        $settings->whatsapp_text_cost = (int) ($data['whatsapp']['text_cost'] ?? 0);
        $settings->whatsapp_template_cost = (int) ($data['whatsapp']['template_cost'] ?? 0);
        $settings->whatsapp_monthly_budget = (int) ($data['whatsapp']['monthly_budget'] ?? 0);

        // Only a value that was typed replaces what is saved; an empty box means "keep it".
        foreach (['token' => 'whatsapp_token', 'app_secret' => 'whatsapp_app_secret', 'verify_token' => 'whatsapp_verify_token'] as $field => $property) {
            $typed = trim((string) ($data['whatsapp'][$field] ?? ''));

            if ($typed !== '') {
                $settings->{$property} = $typed;
            }
        }

        $settings->save();

        $this->whatsapp['token'] = '';
        $this->whatsapp['app_secret'] = '';
        $this->whatsapp['verify_token'] = '';
        $this->toast('WhatsApp credentials saved.');
    }

    /** Ask Meta what it knows about this number, and — with a number — send one real message to it (WAP-001). */
    public function testWhatsAppConnection(TestWhatsAppConnection $test): void
    {
        abort_unless($this->user()->can('settings.manage') || $this->user()->can('integrations.manage'), 403);

        $this->validate(['whatsapp.probe_number' => ['nullable', 'string', 'max:32']]);

        $result = $test->handle(blank($this->whatsapp['probe_number'] ?? null) ? null : trim((string) $this->whatsapp['probe_number']));

        $this->whatsappStatus = $result;
        $this->toast($result['ok']
            ? ($result['probe'] === true
                ? 'Connected, and the test message went out.'
                : 'Connected: Meta recognises the token and phone number.')
            : (string) $result['error'], $result['ok'] ? 'success' : 'error');
    }

    /** Forget the credentials saved here, so the server .env is used again. The .env itself is untouched. */
    public function forgetWhatsApp(IntegrationSettings $settings): void
    {
        abort_unless($this->user()->can('settings.manage') || $this->user()->can('integrations.manage'), 403);

        $settings->whatsapp_waba_id = null;
        $settings->whatsapp_phone_number_id = null;
        $settings->whatsapp_token = null;
        $settings->whatsapp_app_secret = null;
        $settings->whatsapp_verify_token = null;
        $settings->whatsapp_verified_name = null;
        $settings->whatsapp_display_phone = null;
        $settings->whatsapp_quality_rating = null;
        $settings->save();

        $this->whatsapp['waba_id'] = '';
        $this->whatsapp['phone_number_id'] = '';
        $this->whatsappStatus = null;
        $this->toast('Saved credentials removed. The server .env is used again if it holds any.');
    }

    public function render(): View
    {
        $credentials = app(WhatsAppCredentials::class);
        $saved = [
            'token' => (string) ($credentials->token() ?? ''),
            'app_secret' => (string) ($credentials->appSecret() ?? ''),
            'verify_token' => (string) ($credentials->verifyToken() ?? ''),
        ];

        return view('livewire.admin.system.settings-page', [
            'whatsappSaved' => array_map(fn (string $value): string => $this->mask($value), $saved),
            'whatsappHints' => [
                'token' => $saved['token'] !== ''
                    ? 'Saved: '.$this->mask($saved['token']).' — leave empty to keep it.'
                    : 'A system user token with whatsapp_business_messaging and whatsapp_business_management.',
                'app_secret' => $saved['app_secret'] !== ''
                    ? 'Saved: '.$this->mask($saved['app_secret']).' — leave empty to keep it.'
                    : 'Used only to check that an incoming webhook really came from Meta.',
                'verify_token' => $saved['verify_token'] !== ''
                    ? 'Saved: '.$this->mask($saved['verify_token']).' — leave empty to keep it.'
                    : 'Any string you choose; paste the same one into Meta.',
            ],
            'whatsappSource' => $credentials->isConfigured() ? $credentials->source() : null,
            'days' => [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'],
            'roles' => UsersIndex::ROLES,
        ]);
    }

    /** Enough to recognise a key, never enough to use it. */
    private function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return strlen($value) <= 8
            ? str_repeat('•', strlen($value))
            : substr($value, 0, 4).str_repeat('•', 8).substr($value, -4);
    }

    /**
     * "2027-03-23 Pakistan Day" per line → sorted unique list; null when a line is not a valid date.
     *
     * @return list<array{date: string, name: string}>|null
     */
    public static function parseHolidays(string $text): ?array
    {
        $holidays = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})\s*(.*)$/u', $line, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }

            $holidays["{$m[1]}-{$m[2]}-{$m[3]}"] = ['date' => "{$m[1]}-{$m[2]}-{$m[3]}", 'name' => mb_substr(trim($m[4]) ?: 'Holiday', 0, 80)];
        }

        ksort($holidays);

        return array_values($holidays);
    }

    private static function bpToPercent(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');
    }

    private static function percentToBp(mixed $percent): int
    {
        return (int) round(((float) $percent) * 100);
    }

    /**
     * BOT-002/008/010: the assistant's own switches. The API key is typed here but never shown
     * back — an empty box keeps whatever is saved, exactly like the WhatsApp credentials.
     */
    public function saveBot(): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $data = $this->validate([
            'bot.enabled' => ['boolean'],
            'bot.provider' => ['required', 'in:none,anthropic,openai,gemini'],
            'bot.model' => ['nullable', 'string', 'max:64'],
            'bot.system_prompt' => ['nullable', 'string', 'max:8000'],
            'bot.daily_token_cap' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'bot.conversation_turn_limit' => ['nullable', 'integer', 'min:0', 'max:200'],
            'bot.outside_hours_only' => ['boolean'],
            'bot.api_key' => ['nullable', 'string', 'max:512'],
        ]);

        $settings = app(BotSettings::class);
        $settings->enabled = (bool) ($data['bot']['enabled'] ?? false);
        $settings->provider = (string) ($data['bot']['provider'] ?? 'none');
        $settings->model = trim((string) ($data['bot']['model'] ?? ''));
        $settings->system_prompt = trim((string) ($data['bot']['system_prompt'] ?? ''));
        $settings->daily_token_cap = (int) ($data['bot']['daily_token_cap'] ?? 0);
        $settings->conversation_turn_limit = (int) ($data['bot']['conversation_turn_limit'] ?? 0);
        $settings->outside_hours_only = (bool) ($data['bot']['outside_hours_only'] ?? false);

        $typedKey = trim((string) ($this->bot['api_key'] ?? ''));
        if ($typedKey !== '') {
            $settings->api_key = $typedKey;
        }

        $settings->save();

        $this->toast('Bot settings saved.');
    }

    /** BOT-003: pull the published pages' words into the assistant's knowledge. */
    public function syncBotKnowledge(): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $result = app(SyncBotKnowledge::class)->handle();

        $this->toast('Knowledge synced: '.$result['entries'].' entr'.($result['entries'] === 1 ? 'y' : 'ies').' from '.$result['pages'].' published page(s).');
    }

    public function addBotKnowledge(): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        $data = $this->validate([
            'botKnowledgeTitle' => ['required', 'string', 'max:120'],
            'botKnowledgeBody' => ['required', 'string', 'max:6000'],
        ]);

        BotKnowledge::create([
            'title' => trim((string) $data['botKnowledgeTitle']),
            'content' => trim((string) $data['botKnowledgeBody']),
            'source' => 'manual',
            'is_active' => true,
        ]);

        $this->botKnowledgeTitle = '';
        $this->botKnowledgeBody = '';
        $this->toast('Knowledge entry added.');
    }

    public function deleteBotKnowledge(int $id): void
    {
        abort_unless($this->user()->can('settings.manage'), 403);

        BotKnowledge::query()->whereKey($id)->where('source', 'manual')->delete();
        $this->toast('Entry removed.');
    }
}
