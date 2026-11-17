<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Console\Commands\Heartbeat;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Activity;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Sales\Models\BankAccount;
use App\Domain\System\Control\ControlPanel;
use App\Domain\System\Control\PeriodPerformance;
use App\Integrations\Telegram\Contracts\TelegramGateway;
use App\Integrations\Turnstile\TurnstileVerifier;
use App\Models\User;
use App\Support\Reports\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The control view (deep QA slice D). Six numbers, each one query from ControlPanel, each one click
 * from the screen that filters to the same rows; then what needs a person, then the shape of the
 * fortnight and the day's record. No number on this page is typed or estimated.
 */
#[Layout('layouts::admin')]
#[Title('Dashboard')]
class Dashboard extends Component
{
    #[Url]
    public string $preset = 'this_month';

    #[Url]
    public string $line = '';

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    public function mount(): void
    {
        $this->authorize('dashboard.view');
    }

    public function render(): View
    {
        $this->authorize('dashboard.view');
        abort_unless(in_array($this->preset, ReportRange::PRESETS, true), 422, 'Choose a valid period.');
        abort_unless($this->line === '' || BusinessLine::tryFrom($this->line) !== null, 422, 'Choose a business line.');
        if ($this->preset === 'custom') {
            $this->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        }
        $range = ReportRange::fromPreset($this->preset, $this->from, $this->to);
        /** @var User $user */
        $user = Auth::user();
        $panel = app(ControlPanel::class);
        $now = CarbonImmutable::now();

        $canLeads = $user->can('leads.view');
        $ownOnly = ! $user->can('leads.view_all');
        $leads = fn (): Builder => Lead::query()->visibleTo($user);

        $funnel = $canLeads ? PipelineStage::query()->ordered()
            ->withCount(['leads' => fn (Builder $q) => $q->when($ownOnly, fn (Builder $inner) => $inner->where('owner_id', $user->id))])
            ->get() : collect();

        $overdueLeads = $user->can('leads.view')
            ? Lead::query()->visibleTo($user)->followUpDue()
                ->with(['contact', 'owner', 'stage'])
                ->orderByRaw('next_action_at is null')
                ->orderBy('next_action_at')
                ->limit(5)
                ->get()
            : collect();

        // Leads per day, last 14 days (grouped in PHP so it works on MySQL and SQLite).
        $start = $now->subDays(13)->startOfDay();
        $perDay = $canLeads ? $leads()->where('created_at', '>=', $start)->pluck('created_at')
            ->countBy(fn ($date) => CarbonImmutable::parse($date)->format('Y-m-d')) : collect();
        $categories = [];
        $series = [];
        for ($day = $start; $day->lessThanOrEqualTo($now); $day = $day->addDay()) {
            $categories[] = $day->format('j M');
            $series[] = (int) ($perDay[$day->format('Y-m-d')] ?? 0);
        }

        $recent = $canLeads ? Activity::query()
            ->with(['contact', 'user'])
            ->when($ownOnly, fn (Builder $q) => $q->whereHas('lead', fn (Builder $l) => $l->where('owner_id', $user->id)))
            ->latest('occurred_at')
            ->limit(8)
            ->get() : collect();

        $hour = (int) $now->timezone('Asia/Karachi')->format('G');
        $greeting = ($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'))
            .', '.Str::of($user->name)->trim()->before(' ');

        return view('livewire.admin.dashboard', [
            'user' => $user,
            'greeting' => $greeting,
            'range' => $range,
            'periodCards' => app(PeriodPerformance::class)->cards($user, $range, BusinessLine::tryFrom($this->line)),
            'control' => $panel->cards($user),
            'attention' => $panel->attention($user),
            'overdueLeads' => $overdueLeads,
            'funnel' => $funnel,
            'chart' => [
                'type' => 'area',
                'height' => 240,
                'categories' => $categories,
                'series' => [['name' => 'New leads', 'data' => $series]],
            ],
            'recent' => $recent,
            'checklist' => $user->can('system.health') ? $this->checklist($user) : [],
        ]);
    }

    /**
     * Go-live checklist shown until everything is green (docs/06 §9).
     *
     * @return list<array{label: string, done: bool, hint: string}>
     */
    private function checklist(User $user): array
    {
        $heartbeat = Cache::get(Heartbeat::CACHE_KEY);
        $cronOk = is_string($heartbeat) && CarbonImmutable::parse($heartbeat)->greaterThan(now()->subMinutes(3));

        return [
            ['label' => 'Two-factor authentication on your account', 'done' => $user->hasTwoFactorEnabled(), 'hint' => 'Profile → Security'],
            ['label' => 'Telegram alerts linked to your account', 'done' => $user->routeNotificationForTelegram() !== null, 'hint' => 'Profile → Security → Link Telegram'],
            ['label' => 'Telegram bot configured', 'done' => app(TelegramGateway::class)->isConfigured(), 'hint' => 'TELEGRAM_BOT_TOKEN in .env'],
            ['label' => 'Spam protection (Cloudflare Turnstile)', 'done' => app(TurnstileVerifier::class)->enabled(), 'hint' => 'TURNSTILE_* keys in .env'],
            ['label' => 'Cron heartbeat in the last 3 minutes', 'done' => $cronOk, 'hint' => 'hPanel → Cron Jobs → schedule:run'],
            ['label' => 'Bank accounts added for invoices', 'done' => BankAccount::query()->where('is_active', true)->exists(), 'hint' => 'Invoices → Bank accounts'],
        ];
    }
}
