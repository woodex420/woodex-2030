<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Crm\Enums\BusinessLine;
use Illuminate\Database\Eloquent\Model;

final class DescriptionTemplate extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['business_line' => BusinessLine::class, 'is_active' => 'boolean'];
    }
}
