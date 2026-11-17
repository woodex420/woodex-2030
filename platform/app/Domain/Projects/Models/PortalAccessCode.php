<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use App\Domain\Crm\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A six-digit code that opens the client portal for one client (PRJ-006). There are no client passwords: the
 * client asks for a code on the phone number or the email we already have, and the code dies when it is used.
 */
class PortalAccessCode extends Model
{
    protected $guarded = ['id'];

    /** Minutes a code stays usable. */
    public const int LIFETIME_MINUTES = 15;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < 5;
    }
}
