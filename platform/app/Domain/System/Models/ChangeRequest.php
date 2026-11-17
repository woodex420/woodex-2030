<?php

declare(strict_types=1);

namespace App\Domain\System\Models;

use App\Domain\System\Enums\ChangeRequestStatus;
use App\Domain\System\Enums\ChangeRequestType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ideas and issues reported from Telegram (/idea, /issue) or the admin (TGM-006, SYS-010).
 */
class ChangeRequest extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ChangeRequestType::class,
            'status' => ChangeRequestStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reference(): string
    {
        return 'CR-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT);
    }
}
