<?php

declare(strict_types=1);

namespace App\Domain\System\Telegram\Commands;

use App\Domain\Sales\Actions\CollectPayment;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Models\Invoice;
use App\Domain\System\Telegram\TelegramCommand;
use App\Models\User;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * /collect <invoice> <amount> [method] — money collected on the spot (P8.2).
 *
 * Two steps, no state: the first run answers with the invoice and the exact command back; the
 * command repeated with `confirm` records AND verifies the payment (the collector is the verifier),
 * allocates it, numbers a receipt and posts it to the ledger.
 */
final class CollectCommand implements TelegramCommand
{
    public function __construct(
        private readonly CollectPayment $collect,
    ) {}

    public function name(): string
    {
        return '/collect';
    }

    public function usage(): string
    {
        return '/collect <invoice-number> <amount> [cash|bank|cheque|jazzcash|easypaisa|card] [reference]';
    }

    public function description(): string
    {
        return 'record a collected payment: confirm posts, receipts and books it';
    }

    public function permission(): string
    {
        return 'payments.record';
    }

    public function handle(User $user, string $argument): string
    {
        $parts = preg_split('/\s+/', trim($argument)) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));

        if (count($parts) < 2) {
            return 'Collect like this: /collect INV-2026-0007 450000 cash — or with a reference: /collect INV-2026-0007 450000 bank "transfer from yesterday".';
        }

        [$number, $amountText] = [$parts[0], $parts[1]];
        $reference = null;
        $confirmed = false;
        $method = PaymentMethod::Cash;

        foreach (array_slice($parts, 2) as $token) {
            $lower = strtolower($token);

            if ($lower === 'confirm') {
                $confirmed = true;

                continue;
            }

            $resolved = self::methodFrom($lower);

            if ($resolved !== null) {
                $method = $resolved;

                continue;
            }

            $reference = trim(($reference ?? '').' '.str_replace('"', '', $token));
        }

        $invoice = Invoice::query()->where('number', $number)->with('contact')->first();

        if ($invoice === null) {
            return 'There is no invoice '.$number.'. Check the number on the invoice, or send /due for the open ones.';
        }

        $amountMinor = $this->paisa($amountText);

        if ($amountMinor === null) {
            return '"'.$amountText.'" is not an amount. Use rupees, e.g. 450000 or 45000.50.';
        }

        if (! $confirmed) {
            $methodLabel = $method->label();

            return sprintf(
                "Ready to collect:\nInvoice: %s\nClient: %s\nAmount: %s of %s outstanding\nMethod: %s%s\n\nCheck it, then send:\n/collect %s %s %s confirm%s",
                $invoice->number,
                $invoice->contact !== null ? $invoice->contact->name : 'unknown client',
                Money::ofMinor($amountMinor)->format(),
                Money::ofMinor((int) $invoice->balance_minor)->format(),
                $methodLabel,
                $reference !== null ? "\nReference: {$reference}" : '',
                $invoice->number,
                $amountText,
                self::token($method),
                $reference !== null ? ' "'.str_replace('"', '', $reference).'"' : '',
            );
        }

        try {
            $payment = $this->collect->handle($invoice, $amountMinor, $method, $user, $reference);
        } catch (ValidationException $exception) {
            $first = collect($exception->errors())->flatten()->first();

            return 'Not collected — '.(is_string($first) ? $first : 'check the amount against the outstanding balance.');
        }

        $remaining = (int) $invoice->refresh()->balance_minor;

        return sprintf(
            "Collected and receipted %s for %s (%s).\nReceipt: %s — it is booked in the ledger.\n%s",
            Money::ofMinor($amountMinor)->format(),
            $payment->contact !== null ? $payment->contact->name : 'the client',
            $payment->method->label(),
            (string) $payment->receipt_number,
            $remaining > 0
                ? Money::ofMinor($remaining)->format().' is still outstanding on '.$invoice->number.'.'
                : $invoice->number.' is fully settled.',
        );
    }

    /** Rupees (optionally with paisa) → integer paisa, or null when unparseable. */
    private function paisa(string $text): ?int
    {
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $text)) {
            return null;
        }

        $minor = (int) round(((float) $text) * 100);

        return $minor > 0 ? $minor : null;
    }

    private static function methodFrom(string $token): ?PaymentMethod
    {
        return match ($token) {
            'cash' => PaymentMethod::Cash,
            'bank', 'bank_transfer', 'transfer' => PaymentMethod::BankTransfer,
            'cheque', 'check' => PaymentMethod::Cheque,
            'jazzcash', 'jazz' => PaymentMethod::JazzCash,
            'easypaisa', 'easypaisa' => PaymentMethod::Easypaisa,
            'card' => PaymentMethod::Card,
            'gateway' => PaymentMethod::Gateway,
            'other' => PaymentMethod::Other,
            default => null,
        };
    }

    private static function token(PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::Cash => 'cash',
            PaymentMethod::BankTransfer => 'bank',
            PaymentMethod::Cheque => 'cheque',
            PaymentMethod::JazzCash => 'jazzcash',
            PaymentMethod::Easypaisa => 'easypaisa',
            PaymentMethod::Card => 'card',
            PaymentMethod::Gateway => 'gateway',
            PaymentMethod::Other => 'other',
        };
    }
}
