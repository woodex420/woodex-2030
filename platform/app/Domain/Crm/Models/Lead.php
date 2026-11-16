<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Crm\Enums\BusinessLine;
use App\Domain\Crm\Enums\LeadPriority;
use App\Domain\Crm\Enums\LeadSource;
use App\Domain\Crm\Enums\LeadType;
use App\Domain\Crm\Enums\NextActionType;
use App\Domain\Crm\Enums\StageType;
use App\Domain\Crm\Models\Concerns\HasAttachments;
use App\Domain\Sales\Models\Quote;
use App\Models\User;
use App\Settings\SalesSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;

/**
 * An opportunity for one contact in one business line (CRM-003). Created only by CaptureLead.
 */
class Lead extends Model implements HasMedia
{
    /** @use HasFactory<LeadFactory> */
    use HasAttachments, HasFactory, LogsActivity, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'business_line' => BusinessLine::class,
            'source' => LeadSource::class,
            'lead_type' => LeadType::class,
            'priority' => LeadPriority::class,
            'next_action_type' => NextActionType::class,
            'area_sqft' => 'integer',
            'score' => 'integer',
            'expected_value_minor' => 'integer',
            'stage_changed_at' => 'datetime',
            'next_action_at' => 'datetime',
            'first_response_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'sla_breached_at' => 'datetime',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'attribution' => 'array',
            'meta' => 'array',
        ];
    }

    protected static function newFactory(): LeadFactory
    {
        return LeadFactory::new();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('crm')
            ->logOnly(['stage_id', 'owner_id', 'priority', 'business_line', 'service', 'expected_value_minor', 'lost_reason', 'next_action_at'])
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
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<PipelineStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'stage_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->latest('occurred_at');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<SiteVisit, $this>
     */
    public function siteVisits(): HasMany
    {
        return $this->hasMany(SiteVisit::class);
    }

    /**
     * @return HasMany<Quote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Leads in an open (not won/lost) stage.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereHas('stage', fn (Builder $stage) => $stage->where('type', StageType::Open->value));
    }

    /**
     * Follow-ups that should already have happened (deep QA slice D): a next action whose time has
     * passed, or a first reply past its SLA. One definition, used by the dashboard tile, the
     * "needs attention" list and the Leads list's `?due=overdue` filter, so the number and the list
     * under it can never disagree. Closed leads are never due.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeFollowUpDue(Builder $query): Builder
    {
        $now = CarbonImmutable::now();

        return $query->open()->where(fn (Builder $inner): Builder => $inner
            ->where(fn (Builder $next) => $next->whereNotNull('next_action_at')->where('next_action_at', '<', $now))
            ->orWhere(fn (Builder $sla) => $sla
                ->whereNull('first_response_at')
                ->whereNotNull('sla_due_at')
                ->where('sla_due_at', '<', $now)));
    }

    /**
     * Leads a user may see (mirrors LeadPolicy::view).
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->can('leads.view_all') ? $query : $query->where('owner_id', $user->id);
    }

    public function expectedValue(): ?Money
    {
        return $this->expected_value_minor === null ? null : Money::ofMinor((int) $this->expected_value_minor);
    }

    public function isHot(): bool
    {
        return $this->score >= app(SalesSettings::class)->hot_score;
    }

    public function isOverdue(): bool
    {
        return $this->next_action_at !== null && $this->next_action_at->isPast();
    }

    public function isSlaBreached(): bool
    {
        return $this->first_response_at === null && $this->sla_due_at !== null && $this->sla_due_at->isPast();
    }
}
