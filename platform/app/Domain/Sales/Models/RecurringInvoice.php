<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Sales\Enums\RecurringFrequency;
use App\Models\User;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A standing charge: the same lines billed to the same client on a rhythm (slice E, part 2).
 *
 * Nothing is invented per run — the lines, tax rate and due days are set once by a person, and the scheduler
 * only decides *when* the next invoice happens. Pausing stops it; the count and the last date are the proof
 * that it ran.
 */
class RecurringInvoice extends Model
{
    use HasPublicId;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_line' => BusinessLine::class,
            'frequency' => RecurringFrequency::class,
            'lines' => 'array',
            'tax_bp' => 'integer',
            'due_days' => 'integer',
            'starts_on' => 'date',
            'next_issue_on' => 'date',
            'ends_on' => 'date',
            'is_active' => 'boolean',
            'invoice_count' => 'integer',
            'last_issued_at' => 'datetime',
        ];
    }

    /**
     * Schedules the scheduler may bill today: active, due, and not past their end date.
     *
     * @param  Builder<RecurringInvoice>  $query
     * @return Builder<RecurringInvoice>
     */
    public function scopeDue(Builder $query): Builder
    {
        $today = today()->toDateString();

        return $query->where('is_active', true)
            ->whereDate('next_issue_on', '<=', $today)
            ->where(fn (Builder $ends): Builder => $ends
                ->whereNull('ends_on')
                ->orWhereDate('ends_on', '>=', $today));
    }

    /** Subtotal of the standing lines, before tax. */
    public function subtotalMinor(): int
    {
        $total = 0;

        foreach ($this->lines as $line) {
            $total += max(0, (int) ($line['qty'] ?? 1)) * max(0, (int) ($line['rate_minor'] ?? 0));
            $total -= max(0, (int) ($line['discount_minor'] ?? 0));
        }

        return max(0, $total);
    }

    public function taxMinor(): int
    {
        return (int) round($this->subtotalMinor() * $this->tax_bp / 10000);
    }

    public function totalMinor(): int
    {
        return $this->subtotalMinor() + $this->taxMinor();
    }

    public function label(): string
    {
        return $this->title.' · '.$this->contact->name;
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
