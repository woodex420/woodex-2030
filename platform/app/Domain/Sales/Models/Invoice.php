<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectMilestone;
use App\Domain\Sales\Enums\InvoiceKind;
use App\Domain\Sales\Enums\InvoiceStatus;
use App\Support\Concerns\HasPublicId;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Invoice (milestone, manual, variation, credit note). Immutable once issued (INV-003).
 */
class Invoice extends Model
{
    use HasPublicId, LogsActivity, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceKind::class,
            'status' => InvoiceStatus::class,
            'business_line' => BusinessLine::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'rent_minor' => 'integer',
            'shipping_minor' => 'integer',
            'tax_bp' => 'integer',
            'advance_bp' => 'integer',
            'commercial' => 'array',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'balance_minor' => 'integer',
            'issue_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'voided_at' => 'datetime',
            'snapshot' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('billing')
            ->logOnly(['status', 'total_minor', 'paid_minor', 'balance_minor', 'due_date', 'voided_at', 'void_reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<ProjectMilestone, $this>
     */
    public function projectMilestone(): BelongsTo
    {
        return $this->belongsTo(ProjectMilestone::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Money received against this invoice (INV-005). Anything over the balance is client credit, so the amount is
     * cut at what is still owed; the rest stays unallocated on the payment.
     */
    public function applyPayment(int $amountMinor): void
    {
        $paid = min((int) $this->total_minor, (int) $this->paid_minor + $amountMinor);
        $balance = (int) $this->total_minor - $paid;

        $this->forceFill([
            'paid_minor' => $paid,
            'balance_minor' => $balance,
            'status' => $balance <= 0 ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid,
        ])->save();
    }

    /**
     * Issued/partially paid invoices past their due date with a balance (computed, INV-003): what is owed
     * (owing()), at every age but "not due yet". It had its own list of statuses beside owing()'s; the two were
     * equal, and nothing held them so. Now "late" is a part of "owed" by its writing (master list T-044).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->aged('overdue');
    }

    /**
     * The ages of money that is owed, in order, with the words the screens use for them.
     *
     * @var array<string, string>
     */
    public const AGES = [
        'current' => 'Not due yet',
        'd1_30' => '1–30 days late',
        'd31_60' => '31–60 days late',
        'd61_90' => '61–90 days late',
        'd90_plus' => 'More than 90 days late',
    ];

    /**
     * What a client still owes on: issued or part-paid, with a balance. The dashboard's "Money due", the ages
     * below and the list they open are this one set (master list T-044).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeOwing(Builder $query): Builder
    {
        return $query->whereIn('invoices.status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('invoices.balance_minor', '>', 0);
    }

    /**
     * Invoices that are owed on, by how long ago they fell due. The ages are those of the receivables report
     * (RPT-005): not due yet, then 1–30, 31–60, 61–90 and more than 90 days past the due date. An invoice
     * without a due date is not due yet. `open` is all of them, `overdue` every age but the first.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeAged(Builder $query, string $age): Builder
    {
        $today = CarbonImmutable::today();
        $day = fn (int $ago): string => $today->subDays($ago)->toDateString();
        $query->owing();

        return match ($age) {
            'open' => $query,
            'overdue' => $query->whereDate('invoices.due_date', '<', $day(0)),
            'current' => $query->where(fn (Builder $q) => $q->whereNull('invoices.due_date')->orWhereDate('invoices.due_date', '>=', $day(0))),
            'd1_30' => $query->whereDate('invoices.due_date', '<', $day(0))->whereDate('invoices.due_date', '>=', $day(30)),
            'd31_60' => $query->whereDate('invoices.due_date', '<', $day(30))->whereDate('invoices.due_date', '>=', $day(60)),
            'd61_90' => $query->whereDate('invoices.due_date', '<', $day(60))->whereDate('invoices.due_date', '>=', $day(90)),
            'd90_plus' => $query->whereDate('invoices.due_date', '<', $day(90)),
            default => throw new InvalidArgumentException("An invoice has no age [{$age}]."),
        };
    }

    /**
     * Invoices issued in a period: by their issue date, and only those that count as revenue (issued, part-paid
     * or paid; a draft and a void invoice do not, RPT-005). The days are whole days on the studio's clock.
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeIssuedBetween(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        return $query->whereIn('invoices.status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value, InvoiceStatus::Paid->value])
            ->whereNotNull('invoices.issue_date')
            ->when($from !== null, fn (Builder $q) => $q->whereDate('invoices.issue_date', '>=', $from->toDateString()))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('invoices.issue_date', '<=', $to->toDateString()));
    }

    public function balance(): Money
    {
        return Money::ofMinor((int) $this->balance_minor, (string) $this->currency);
    }

    public function total(): Money
    {
        return Money::ofMinor((int) $this->total_minor, (string) $this->currency);
    }
}
