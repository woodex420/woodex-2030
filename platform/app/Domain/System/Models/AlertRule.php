<?php

declare(strict_types=1);

namespace App\Domain\System\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Event → recipients → channels routing for alerts (AUT-003, TGM-002).
 */
class AlertRule extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'recipients' => 'array',
            'channels' => 'array',
            'respect_quiet_hours' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
