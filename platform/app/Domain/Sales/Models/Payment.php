<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Concerns\HasAttachments;
use App\Domain\Crm\Models\Contact;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Enums\PaymentMethod;
use App\Domain\Sales\Enums\PaymentStatus;
use App\Models\User;
use App\Support\Concerns\HasPublicId;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;

/**
 * Money received (or claimed by a client upload, pending verification) (INV-005, INV-006).
 */
class Payment extends Model implements HasMedia
{
    use HasAttachments, HasPublicId, LogsActivity, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'received_on' => 'date',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'verified_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('billing')
            ->logOnly(['status', 'amount_minor', 'method', 'reference', 'verified_by', 'receipt_number'])
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The invoice this payment came in against, when it came in against one (INV-006).
     *
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<BankAccount, $this>
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Money that came in during a period: verified payments, by the day they were received (RPT-005). For one
     * business line it is the payments of which a part was set against an invoice of that line; cash that is set
     * against nothing belongs to no line. The revenue report, the dashboard's figure and the list that figure
     * opens ask this one scope (master list T-044).
     *
     * @param  Builder<Payment>  $query
     * @return Builder<Payment>
     */
    public function scopeCollectedBetween(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to, ?BusinessLine $line = null): Builder
    {
        return $query->where('payments.status', PaymentStatus::Verified->value)
            ->when($from !== null, fn (Builder $q) => $q->whereDate('payments.received_on', '>=', $from->toDateString()))
            ->when($to !== null, fn (Builder $q) => $q->whereDate('payments.received_on', '<=', $to->toDateString()))
            ->when($line !== null, fn (Builder $q) => $q->whereHas('allocations.invoice', fn (Builder $invoice) => $invoice->where('business_line', $line->value)));
    }

    public function amount(): Money
    {
        return Money::ofMinor((int) $this->amount_minor);
    }

    public function unallocated(): Money
    {
        return Money::ofMinor((int) $this->amount_minor - (int) $this->allocations()->sum('amount_minor'));
    }
}
