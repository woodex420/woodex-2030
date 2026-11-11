<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Accounts\Models\Vendor;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Models\Invoice;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Quote;
use App\Livewire\Concerns\InteractsWithAdmin;
use App\Support\AdminNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * Command palette (GLB-002): Ctrl/⌘ K from anywhere. Finds contacts and leads by name, phone in any
 * format (0322…, +92 322…, 92322…), email or lead reference, within the user's visibility.
 */
class GlobalSearch extends Component
{
    use InteractsWithAdmin;

    public string $term = '';

    public function render(): View
    {
        $user = $this->user();
        $term = mb_substr(trim($this->term), 0, 160);

        /** @var Collection<int, Contact> $contacts */
        $contacts = collect();
        /** @var Collection<int, Lead> $leads */
        $leads = collect();
        /** @var Collection<int, Company> $companies */
        $companies = collect();
        /** @var Collection<int, Quote> $quotes */
        $quotes = collect();

        if (mb_strlen($term) >= 2) {
            if ($user->can('contacts.view')) {
                $contacts = Contact::query()->visibleTo($user)->search($term)->withCount('leads')
                    ->orderByDesc('last_activity_at')->limit(6)->get();
            }

            if ($user->can('contacts.view')) {
                $companies = Company::query()->search($term)->withCount('contacts')->orderBy('name')->limit(4)->get();
            }

            if ($user->can('leads.view')) {
                $leads = Lead::query()->visibleTo($user)->with(['contact:id,name', 'stage'])
                    ->where(fn (Builder $q) => $q->where('ref', 'like', "%{$term}%")->orWhereHas('contact', fn (Builder $c) => $c->search($term)))
                    ->latest('id')->limit(6)->get();
            }

            if ($user->can('quotes.view')) {
                $quotes = Quote::query()->visibleTo($user)->with('contact:id,name')
                    ->where(fn (Builder $q) => $q->where('number', 'like', "%{$term}%")->orWhere('title', 'like', "%{$term}%"))
                    ->latest('id')->limit(5)->get();
            }
        }

        return view('livewire.admin.global-search', [
            'contacts' => $contacts,
            'leads' => $leads,
            'companies' => $companies,
            'quotes' => $quotes,
            'searched' => mb_strlen($term) >= 2,
            'pages' => $this->pages($term),
            'records' => $this->recordMatches($term),
        ]);
    }

    /** @return Collection<int, array{group: string, label: string, detail: string, url: string}> */
    private function recordMatches(string $term): Collection
    {
        /** @var Collection<int, array{group: string, label: string, detail: string, url: string}> $records */
        $records = collect();
        if (mb_strlen($term) < 2) {
            return $records;
        }
        $user = $this->user();
        $like = '%'.$term.'%';
        if ($user->can('projects.view')) {
            foreach (Project::query()->where(fn (Builder $q) => $q->where('code', 'like', $like)->orWhere('name', 'like', $like))
                ->latest('id')->limit(5)->get() as $project) {
                $records->push(['group' => 'Projects', 'label' => $project->code.' · '.$project->name, 'detail' => 'Open project', 'url' => route('admin.projects.show', $project)]);
            }
        }
        if ($user->can('accounts.view')) {
            foreach (Vendor::query()->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('email', 'like', $like))
                ->latest('id')->limit(5)->get() as $vendor) {
                $records->push(['group' => 'Vendors', 'label' => $vendor->name, 'detail' => 'Open vendor register', 'url' => route('admin.projects.costs', ['tab' => 'vendors'])]);
            }
        }
        if ($user->can('invoices.view')) {
            foreach (Invoice::query()->with('contact:id,name')->where(fn (Builder $q) => $q->where('number', 'like', $like)
                ->orWhereHas('contact', fn (Builder $c) => $c->search($term)))->latest('id')->limit(5)->get() as $invoice) {
                $records->push(['group' => 'Invoices', 'label' => $invoice->number ?? 'Draft invoice', 'detail' => $invoice->contact->name ?? '', 'url' => route('admin.invoices.show', $invoice)]);
            }
            foreach (Payment::query()->with('contact:id,name')->where(fn (Builder $q) => $q->where('receipt_number', 'like', $like)->orWhere('reference', 'like', $like))
                ->latest('id')->limit(5)->get() as $payment) {
                $label = $payment->receipt_number ?: ($payment->reference ?: 'Payment');
                $records->push(['group' => 'Payments', 'label' => $label, 'detail' => $payment->contact->name ?? '', 'url' => route('admin.payments.index', ['search' => $label])]);
            }
        }

        return $records;
    }

    /** @return Collection<int, array{label: string, icon: string, url: string, fullDocument: bool}> */
    private function pages(string $term): Collection
    {
        $user = $this->user();
        $pages = collect(AdminNavigation::for($user))->flatMap(fn (array $group) => $group['items'])
            ->filter(fn (array $item): bool => $item['route'] !== null)
            ->map(fn (array $item): array => ['label' => $item['label'], 'icon' => $item['icon'], 'url' => route($item['route']), 'fullDocument' => in_array($item['route'], AdminNavigation::FULL_DOCUMENT, true)]);
        foreach ([
            ['Operations', 'dashboard', 'admin.dashboard', 'dashboard.view', ['tab' => 'operations']],
            ['My Day', 'sun', 'admin.dashboard', 'leads.view', ['tab' => 'my-day']],
            ['Pipeline', 'kanban', 'admin.leads.index', 'leads.view', ['tab' => 'pipeline']],
            ['Site visits', 'calendar', 'admin.tasks.index', 'leads.view', ['tab' => 'visits']],
            ['Chart of Accounts', 'book', 'admin.accounts.chart', 'accounts.view', []],
            ['Journals', 'layers', 'admin.accounts.journals', 'accounts.view', []],
            ['Trial balance', 'chart', 'admin.accounts.trial', 'accounts.view', []],
            ['Profit & loss', 'chart', 'admin.accounts.profit', 'accounts.view', []],
            ['Periods', 'clock', 'admin.accounts.periods', 'accounts.view', []],
            ['Payment Methods', 'wallet', 'admin.accounts.methods', 'accounts.view', []],
            ['Import a lead sheet', 'upload', 'admin.leads.import', 'system.import', []],
            ['Vendors', 'users', 'admin.projects.costs', 'accounts.view', ['tab' => 'vendors']],
            ['Project costs', 'wallet', 'admin.projects.costs', 'projects.view', ['tab' => 'projects']],
            ['Integrations', 'link', 'admin.integrations', 'integrations.manage', []],
            ['Data import', 'upload', 'admin.data-import', 'system.import', []],
            ['Users & roles', 'shield', 'admin.users.index', 'users.manage', []],
            ['Lead assignment', 'users', 'admin.assignment-rules', 'settings.manage', []],
            ['Canned replies', 'chat', 'admin.canned-replies', 'settings.manage', []],
            ['System health', 'health', 'admin.system.health', 'system.health', []],
            ['Audit log', 'activity', 'admin.system.audit', 'audit.view', []],
            ['Change requests', 'tasks', 'admin.system.change-requests', 'system.change_requests', []],
            ['Alert rules', 'bell', 'admin.system.alert-rules', 'settings.manage', []],
            ['Recycle bin', 'trash', 'admin.recycle-bin', 'leads.delete', []],
            ['WhatsApp templates', 'chat', 'admin.whatsapp.templates', 'integrations.manage', []],
            ['Quotation templates', 'quote', 'admin.quotes.index', 'quotes.view', ['tab' => 'templates']],
            ['Rate book', 'book', 'admin.sales.documents', 'quotes.view', ['tab' => 'rate-book']],
            ['Description library', 'file', 'admin.sales.documents', 'quotes.view', ['tab' => 'descriptions']],
            ['Document design', 'palette', 'admin.sales.documents', 'settings.manage', ['tab' => 'design']],
            ['Receivables', 'invoice', 'admin.payments.index', 'invoices.view', ['tab' => 'receivables']],
            ['Sales funnel', 'chart', 'admin.reports.index', 'reports.view', ['tab' => 'funnel']],
            ['Revenue & collections', 'chart', 'admin.reports.index', 'reports.finance', ['tab' => 'revenue']],

        ] as [$label, $icon, $route, $permission, $parameters]) {
            if ($route === 'admin.projects.costs' && ! $user->can('accounts.view')) {
                continue;
            }
            if ($route === 'admin.sales.documents' && ! $user->can('quotes.view')) {
                continue;
            }
            if ($route === 'admin.reports.index' && ! $user->can('reports.view')) {
                continue;
            }
            if (Route::has($route) && $user->can($permission) && ($route !== 'admin.leads.import' || $user->hasRole('owner'))) {
                $pages->push(['label' => $label, 'icon' => $icon, 'url' => route($route, $parameters), 'fullDocument' => false]);
            }
        }

        return $pages->unique('url')->filter(fn (array $page): bool => $term === '' || str_contains(mb_strtolower($page['label']), mb_strtolower($term)))->values();
    }
}
