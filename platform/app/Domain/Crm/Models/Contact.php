<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Conversations\Models\Conversation;
use App\Domain\Crm\Enums\ClientType;
use App\Domain\Crm\Models\Concerns\HasAttachments;
use App\Domain\Crm\Policies\ContactPolicy;
use App\Domain\Projects\Models\PortalAccessCode;
use App\Domain\Projects\Models\Project;
use App\Domain\Sales\Models\Quote;
use App\Models\User;
use App\Support\Phone;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;

/**
 * A person: prospect or client. One record per person across all channels (CRM-001).
 */
class Contact extends Model implements HasMedia
{
    /** @use HasFactory<ContactFactory> */
    use HasAttachments, HasFactory, LogsActivity, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'client_type' => ClientType::class,
            'is_client' => 'boolean',
            'became_client_at' => 'datetime',
            'consent_marketing' => 'boolean',
            'consent_whatsapp' => 'boolean',
            'opted_out_at' => 'datetime',
            'anonymised_at' => 'datetime',
            'meta' => 'array',
            'last_activity_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('crm')
            ->logOnly(['name', 'phone', 'alt_phone', 'whatsapp', 'email', 'city', 'area', 'client_type', 'is_client', 'owner_id', 'opted_out_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
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
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class)->latest();
    }

    /**
     * @return HasMany<PortalAccessCode, $this>
     */
    public function portalCodes(): HasMany
    {
        return $this->hasMany(PortalAccessCode::class);
    }

    /**
     * @return HasMany<Quote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->latest('occurred_at');
    }

    /**
     * The conversations this person has had, across every channel (SYS-013 export/anonymise).
     *
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Find by any of the stored phone columns (already normalised to E.164).
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeWherePhone(Builder $query, string $e164): Builder
    {
        return $query->where(function (Builder $inner) use ($e164): void {
            $inner->where('phone', $e164)->orWhere('alt_phone', $e164)->orWhere('whatsapp', $e164);
        });
    }

    /**
     * Name, phone (any format) or email search used by lists and global search (GLB-002).
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $phone = Phone::normalize($term);

        return $query->where(function (Builder $inner) use ($term, $phone): void {
            $inner->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%");

            if ($phone !== null) {
                $inner->orWhere('phone', $phone)->orWhere('alt_phone', $phone)->orWhere('whatsapp', $phone);
            }
        });
    }

    /**
     * Contacts a user may see (mirrors ContactPolicy).
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (ContactPolicy::seesAll($user)) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($user): void {
            $inner->where('owner_id', $user->id)
                ->orWhereHas('leads', fn (Builder $lead) => $lead->where('owner_id', $user->id));
        });
    }

    /**
     * Other contacts that share a phone number, email or exact name (CRM-012).
     *
     * @return Collection<int, Contact>
     */
    public function possibleDuplicates(int $limit = 5): Collection
    {
        $phones = array_values(array_filter([$this->phone, $this->alt_phone, $this->whatsapp]));

        return self::query()
            ->whereKeyNot($this->id)
            ->where(function (Builder $q) use ($phones): void {
                if ($phones !== []) {
                    $q->whereIn('phone', $phones)->orWhereIn('alt_phone', $phones)->orWhereIn('whatsapp', $phones);
                }

                if ($this->email !== null) {
                    $q->orWhere('email', $this->email);
                }

                $q->orWhereRaw('lower(name) = ?', [mb_strtolower(trim($this->name))]);
            })
            ->withCount('leads')
            ->limit($limit)
            ->get();
    }

    public function firstName(): string
    {
        return (string) Str::of($this->name)->trim()->explode(' ')->first();
    }

    public function whatsappNumber(): ?string
    {
        return $this->whatsapp ?? $this->phone;
    }
}
