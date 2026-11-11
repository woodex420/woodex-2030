<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram;

use App\Domain\Crm\Actions\AssignLead;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Actions\DecideQuoteApproval;
use App\Domain\Sales\Actions\RejectPayment;
use App\Domain\Sales\Actions\VerifyPayment;
use App\Domain\Sales\Exceptions\QuoteActionDenied;
use App\Domain\Sales\Models\Payment;
use App\Domain\System\Models\Approval;
use App\Models\User;
use App\Support\Money;
use Throwable;

/**
 * Inline button presses, answered with the linked user's own permissions (TGM-005, P5 step S3).
 *
 * A button carries `action:id:expires` — the expiry is the whole point: a button someone pressed a day after
 * the alert must not silently act on a world that has changed, so it answers "expired" instead. Sensitive
 * actions (void, refund, role changes) are never offered here; what is offered runs the same domain actions
 * the admin uses, so the timeline records who did it and the rules (never approve your own request) hold on
 * Telegram exactly as they hold on the screen.
 */
final class TelegramCallbackBus
{
    /** How long a button works after the alert that carried it (TGM-007). */
    public const BUTTON_LIFETIME_HOURS = 24;

    public function __construct(
        private readonly DecideQuoteApproval $decideApproval,
        private readonly AssignLead $assignLead,
        private readonly VerifyPayment $verifyPayment,
        private readonly RejectPayment $rejectPayment,
    ) {}

    /**
     * The callback data a button carries: action, what it acts on, and when the button dies.
     */
    public static function button(string $action, int $id): string
    {
        return sprintf('%s:%d:%d', $action, $id, now()->addHours(self::BUTTON_LIFETIME_HOURS)->getTimestamp());
    }

    /**
     * Run a button press. The reply is shown to the presser twice: as a toast on the button and as a message
     * in the chat, so the outcome is never lost when the toast scrolls away.
     */
    public function dispatch(?User $user, string $data): string
    {
        if ($user === null) {
            return 'This chat is not linked to Woodex OS, so the button cannot act. Link it from your profile.';
        }

        if (! $user->canUseTelegram()) {
            return TelegramCommandBus::OWNER_ONLY_MESSAGE;
        }

        [$action, $id, $expires] = array_pad(explode(':', $data, 3) + [''], 3, '');

        if ($action === '' || ! ctype_digit($id) || ! ctype_digit($expires)) {
            return 'That button is not recognised anymore — open Woodex OS.';
        }

        if (now()->getTimestamp() > (int) $expires) {
            return 'This button has expired (buttons live '.self::BUTTON_LIFETIME_HOURS.' hours). Open Woodex OS to act.';
        }

        try {
            return match ($action) {
                'assign' => $this->assign($user, (int) $id),
                'approve' => $this->decide($user, (int) $id, true),
                'reject' => $this->decide($user, (int) $id, false, 'Rejected from Telegram'),
                'payverify' => $this->verifyPayment($user, (int) $id),
                'payreject' => $this->rejectPayment($user, (int) $id),
                default => 'That button is not recognised anymore — open Woodex OS.',
            };
        } catch (QuoteActionDenied $denied) {
            // A refusal with a reason the person can act on, not an error.
            return $denied->getMessage();
        } catch (Throwable $exception) {
            report($exception);

            return 'Something went wrong — the team has been notified. Open Woodex OS to act.';
        }
    }

    private function assign(User $user, int $leadId): string
    {
        if (! $user->can('leads.assign')) {
            return 'Your role cannot assign leads.';
        }

        /** @var Lead|null $lead */
        $lead = Lead::query()->visibleTo($user)->find($leadId);

        if ($lead === null) {
            return 'That lead is not visible to you (or no longer exists).';
        }

        $to = $this->assignLead->handle($lead, $user, $user, "Assigned to {$user->name} from Telegram");

        return $to === null
            ? 'Could not assign — no one to assign to.'
            : sprintf('%s (%s) is now yours. /lead %s for the details.', $lead->ref, $lead->contact->name, $lead->ref);
    }

    private function decide(User $user, int $approvalId, bool $approve, ?string $reason = null): string
    {
        if (! $user->can('quotes.approve')) {
            return 'Only the owner or a sales manager can decide approvals.';
        }

        /** @var Approval|null $approval */
        $approval = Approval::query()->with('approvable')->find($approvalId);

        if ($approval === null) {
            return 'That approval no longer exists.';
        }

        $quote = $this->decideApproval->handle($approval, $user, $approve, $reason);

        return $approve
            ? sprintf('Approved: %s. The requester has been told they can send it.', $quote->label())
            : sprintf('Declined: %s. The requester has been told why.', $quote->label());
    }

    private function verifyPayment(User $user, int $paymentId): string
    {
        if (! $user->can('payments.verify')) {
            return 'Your role cannot verify payments.';
        }

        /** @var Payment|null $payment */
        $payment = Payment::query()->find($paymentId);

        if ($payment === null) {
            return 'That payment no longer exists.';
        }

        $this->verifyPayment->handle($payment, $user);

        return sprintf('Payment verified: %s · %s. Thank you.', $payment->contact->name, Money::ofMinor((int) $payment->amount_minor)->format());
    }

    private function rejectPayment(User $user, int $paymentId): string
    {
        if (! $user->can('payments.verify')) {
            return 'Your role cannot verify payments.';
        }

        /** @var Payment|null $payment */
        $payment = Payment::query()->find($paymentId);

        if ($payment === null) {
            return 'That payment no longer exists.';
        }

        $this->rejectPayment->handle($payment, 'Not matched (decided from Telegram)', $user);

        return sprintf('Payment marked not matched: %s. The accountant can add detail in Woodex OS.', $payment->contact->name);
    }
}
