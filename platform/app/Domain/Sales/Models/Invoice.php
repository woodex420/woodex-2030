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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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
     * Issued/partially paid invoices past their due date with a balance (computed, INV-003).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('balance_minor', '>', 0)
            ->whereDate('due_date', '<', now()->toDateString());
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
