<?php

declare(strict_types=1);

namespace App\Domain\System\Control;

use App\Domain\Accounts\Enums\BillStatus;
use App\Domain\Accounts\Models\Bill;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Crm\Models\Lead;
use App\Domain\Marketing\Models\SocialPost;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Models\Payment;
use App\Domain\Sales\Models\Quote;
use App\Domain\System\Enums\ChangeRequestStatus;
use App\Domain\System\Models\ChangeRequest;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Money;

/** Read-only cross-module summaries. Each query is gated before execution, not hidden after rendering. */
final class OperationsSummary
{
    public const REVIEW_PERMISSIONS = ['quotes.approve', 'payments.verify', 'social.manage', 'system.change_requests'];

    public static function canReview(User $user): bool
    {
        return $user->canAny(self::REVIEW_PERMISSIONS);
    }

    /** @return list<array{title: string, detail: string, href: string, icon: string}> */
    public function sections(User $user): array
    {
        $rows = [];
        $panel = app(ControlPanel::class);
        if ($user->can('leads.view')) {
            $rows[] = $this->row('CRM', Lead::query()->visibleTo($user)->open()->count().' open enquiries · '.$panel->overdueFollowUps($user).' follow-ups overdue', 'admin.leads.index', 'users');
        }
        if ($user->can('quotes.view')) {
            $drafts = Quote::query()->visibleTo($user)->where('status', QuoteStatus::Draft)->count();
            $rows[] = $this->row('Sales', $drafts.' draft quotations in your permitted book. Sent documents keep their frozen versions.', 'admin.quotes.index', 'quote');
        }
        if ($user->can('accounts.view')) {
            $due = (int) Bill::query()->whereIn('status', [BillStatus::Received, BillStatus::PartiallyPaid])->selectRaw('sum(total_minor - paid_minor) as outstanding')->value('outstanding');
            $rows[] = $this->row('Accounts', Money::ofMinor($due)->format(app(SalesSettings::class)->lakh_grouping).' payable on received/part-paid bills. Ledger reports remain the accounting source.', 'admin.accounts.payables', 'banknote');
        }
        if ($user->can('projects.view')) {
            $active = Project::query()->where('status', ProjectStatus::Active);
            $rows[] = $this->row('Projects', (clone $active)->count().' active · '.(clone $active)->whereDate('target_date', '<', today())->count().' past target date', 'admin.projects.index', 'hammer');
        }
        if ($user->can('inbox.view')) {
            $rows[] = $this->row('Inbox', Conversation::query()->where('unread_count', '>', 0)->count().' conversations with unread messages. This counts threads, not individual messages.', 'admin.inbox', 'inbox');
        }
        if ($user->can('automation.manage')) {
            $failed = $panel->automationsFailing();
            $rows[] = $this->row('Automation', $failed['total'].' recorded failures · '.count($failed['stale']).' stale task reports. No recorded run is not proof of delivery.', 'admin.automation.index', 'zap');
        }
        if ($user->can('social.manage')) {
            $rows[] = $this->row('Marketing', SocialPost::query()->where('status', SocialPost::SCHEDULED)->count().' scheduled posts · '.SocialPost::query()->where('status', SocialPost::FAILED)->count().' failed. External publishing needs verified provider access.', 'admin.marketing.index', 'megaphone');
        }
        if ($user->can('website.edit')) {
            $rows[] = $this->row('Website', 'Audit the actual v1 page files, forms, assets and published output. A saved editor screen is not publishing proof.', 'admin.website.health', 'globe');
        }

        return $rows;
    }

    /** Existing review work, not new approval policies or direct cross-domain writes.
     * @return list<array{title: string, detail: string, href: string, icon: string}>
     */
    public function reviews(User $user): array
    {
        $rows = [];
        if ($user->can('payments.verify') && $user->can('invoices.view')) {
            $rows[] = $this->row('Payment verification', Payment::query()->where('status', PaymentStatus::PendingVerification)->count().' reported payments awaiting verification. Review in Payments; this is not money received until verified.', 'admin.payments.index', 'wallet');
        }
        if ($user->can('social.manage')) {
            $rows[] = $this->row('Publishing review', SocialPost::query()->where('status', SocialPost::IN_REVIEW)->where('created_by', '!=', $user->id)->count().' posts awaiting their existing publishing decision.', 'admin.marketing.index', 'megaphone');
        }
        if ($user->can('system.change_requests')) {
            $rows[] = $this->row('System change requests', ChangeRequest::query()->whereNotIn('status', [ChangeRequestStatus::Done, ChangeRequestStatus::Rejected])->count().' open requests. Acceptance does not deploy code.', 'admin.system.change-requests', 'settings');
        }

        return $rows;
    }

    /** @return array{title: string, detail: string, href: string, icon: string} */
    private function row(string $title, string $detail, string $route, string $icon): array
    {
        return ['title' => $title, 'detail' => $detail, 'href' => route($route), 'icon' => $icon];
    }
}
