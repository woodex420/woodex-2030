<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Conversations\Models\Message;
use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Models\Company;
use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Enums\DiscountType;
use App\Domain\Sales\Enums\MilestonePlanKey;
use App\Domain\Sales\Enums\QuoteStatus;
use App\Domain\Sales\Policies\QuotePolicy;
use App\Models\User;
use App\Support\Concerns\HasPublicId;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Living quotation document. Every send creates an immutable QuoteVersion snapshot (D-15).
 * Totals are written only by App\Domain\Sales\Services\QuoteCalculator (D-14).
 */
class Quote extends Model
{
    use HasPublicId, LogsActivity, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_line' => BusinessLine::class,
            'status' => QuoteStatus::class,
            'discount_type' => DiscountType::class,
            'revision' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_value' => 'integer',
            'discount_minor' => 'integer',
            'other_charges_minor' => 'integer',
            'shipping_minor' => 'integer',
            'advance_bp' => 'integer',
            'tax_bp' => 'integer',
            'tax_minor' => 'integer',
            'rounding_minor' => 'integer',
            'total_minor' => 'integer',
            'internal_cost_minor' => 'integer',
            'margin_bp' => 'integer',
            'view_count' => 'integer',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
            'first_viewed_at' => 'datetime',
            'last_viewed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'acceptance_hint_at' => 'datetime',
            'declined_at' => 'datetime',
            'expired_at' => 'datetime',
            'approved_at' => 'datetime',
            'signatories' => 'array',
            'meta' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('sales')
            // Money is not logged here: drafts are recalculated on every edit, and every sent revision keeps an
            // immutable snapshot with its totals (quote_versions, D-15).
            ->logOnly(['status', 'revision', 'valid_until', 'owner_id', 'approved_by'])
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
     * @return BelongsTo<Estimate, $this>
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * The WhatsApp reply that sounded like a yes (D-112): kept so the studio can read what the client wrote.
     *
     * @return BelongsTo<Message, $this>
     */
    public function acceptanceHintMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'acceptance_hint_message_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<QuoteTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(QuoteTemplate::class, 'template_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<QuoteSection, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(QuoteSection::class)->orderBy('position');
    }

    /**
     * @return HasMany<QuoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('position');
    }

    /**
     * @return HasMany<QuoteMilestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(QuoteMilestone::class)->orderBy('position');
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<QuoteVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(QuoteVersion::class)->orderBy('revision');
    }

    /**
     * @return HasMany<QuoteEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(QuoteEvent::class)->latest('occurred_at');
    }

    /**
     * @return HasOne<Project, $this>
     */
    public function project(): HasOne
    {
        return $this->hasOne(Project::class);
    }

    /**
     * Quotes a user may open: everyone with leads.view_all or invoices.manage, otherwise their own quotes and the
     * quotes of leads they own (same rule as contacts, D-53).
     *
     * @param  Builder<Quote>  $query
     * @return Builder<Quote>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (QuotePolicy::seesAll($user)) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($user): void {
            $inner->where('owner_id', $user->id)
                ->orWhereHas('lead', fn (Builder $lead) => $lead->where('owner_id', $user->id));
        });
    }

    /**
     * Number, title, client name or phone.
     *
     * @param  Builder<Quote>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $phone = Phone::normalize($term);

        $query->where(function (Builder $q) use ($term, $phone): void {
            $q->where('number', 'like', "%{$term}%")
                ->orWhere('title', 'like', "%{$term}%")
                ->orWhereHas('contact', function (Builder $contact) use ($term, $phone): void {
                    $contact->where('name', 'like', "%{$term}%");

                    if ($phone !== null) {
                        $contact->orWhere('phone', $phone)->orWhere('alt_phone', $phone)->orWhere('whatsapp', $phone);
                    }
                })
                ->orWhereHas('company', fn (Builder $company) => $company->where('name', 'like', "%{$term}%"));
        });
    }

    /**
     * The builder edits drafts (and quotes sent back with changes requested). Sent quotes are revised in P2
     * part 2: editing them creates a new revision instead of changing the sent document (QUO-013).
     */
    public function isEditable(): bool
    {
        return in_array($this->status, [QuoteStatus::Draft, QuoteStatus::ChangesRequested], true);
    }

    public function milestonePlan(): MilestonePlanKey
    {
        return MilestonePlanKey::tryFrom((string) ($this->meta['milestone_plan'] ?? '')) ?? MilestonePlanKey::Execution;
    }

    public function total(): Money
    {
        return Money::ofMinor((int) $this->total_minor, (string) $this->currency);
    }

    /**
     * "WI-10123", "WI-10123 R1", "WI-10123 · Option A".
     */
    public function label(): string
    {
        return $this->number
            .($this->revision > 0 ? ' R'.$this->revision : '')
            .($this->option_label !== '' && $this->option_label !== null ? ' · '.$this->option_label : '');
    }
}
