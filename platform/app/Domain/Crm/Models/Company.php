<?php

declare(strict_types=1);

namespace App\Domain\Crm\Models;

use App\Domain\Sales\Models\Quote;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Client organisation (company, developer, architect firm) (CRM-002).
 */
class Company extends Model
{
    use SoftDeletes;

    /** @var array<string, string> */
    public const TYPES = [
        'company' => 'Company',
        'developer' => 'Developer / builder',
        'architect' => 'Architect / design firm',
        'hospitality' => 'Hotel / restaurant',
        'institution' => 'School / hospital / NGO',
        'other' => 'Other',
    ];

    protected $guarded = ['id'];

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst((string) $this->type);
    }

    /**
     * Name, phone (any format), email or NTN.
     *
     * @param  Builder<Company>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $phone = Phone::normalize($term);

        $query->where(function (Builder $q) use ($term, $phone): void {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('ntn', 'like', "%{$term}%");

            if ($phone !== null) {
                $q->orWhere('phone', $phone);
            }
        });
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
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
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
