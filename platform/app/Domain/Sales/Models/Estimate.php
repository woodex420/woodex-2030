<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Enums\EstimateStatus;
use App\Models\User;
use App\Support\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A staff estimate for a service line (P8.3, docs/19 §7): inputs, a frozen rate snapshot and the
 * computed range. Estimates never reach a client by themselves — conversion creates a quotation
 * draft a person completes and sends. `computed` is the honest record of what was calculated,
 * with the rates and effective date it used.
 *
 * @property int $id
 * @property string $number
 * @property string $service_key
 * @property string $title
 * @property EstimateStatus $status
 * @property array{quantity: string, unit: string, finish: string} $inputs
 * @property array{effective_on: string, rate_minor: int, headline_minor: int, range_low_minor: int, range_high_minor: int, lines: list<array<string, int|string>>, lines_minor: int, contingency_minor: int, subtotal_minor: int, total_minor: int} $computed
 * @property list<array{catalog_item_id: int, qty: string, note?: string}> $catalog_lines
 */
class Estimate extends Model
{
    use HasPublicId, LogsActivity, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'inputs' => 'array',
            'catalog_lines' => 'array',
            'computed' => 'array',
            'contingency_bp' => 'integer',
            'status' => EstimateStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sales')
            ->logOnly(['number', 'service_key', 'title', 'status', 'contingency_bp', 'quote_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The priced catalog items on this estimate, resolved fresh (the snapshot keeps what was saved).
     *
     * @return Collection<int, CatalogItem>
     */
    public function catalogItems()
    {
        $ids = collect($this->catalog_lines ?? [])->pluck('catalog_item_id')->filter()->values()->all();

        return CatalogItem::query()->whereIn('id', $ids)->get()->keyBy('id');
    }
}
