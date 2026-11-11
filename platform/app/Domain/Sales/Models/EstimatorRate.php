<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Website estimator rate per service and finish level (WEB-006). Also the bot's only price source (BOT-007).
 */
class EstimatorRate extends Model
{
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'essential_minor' => 'integer',
            'standard_minor' => 'integer',
            'premium_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function rateFor(string $finish): Money
    {
        return Money::ofMinor((int) match ($finish) {
            'essential' => $this->essential_minor,
            'premium' => $this->premium_minor,
            default => $this->standard_minor,
        });
    }
}
