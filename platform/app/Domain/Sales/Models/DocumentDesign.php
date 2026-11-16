<?php

declare(strict_types=1);

namespace App\Domain\Sales\Models;

use App\Domain\Crm\Enums\BusinessLine;
use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed>|null $furniture_style */
final class DocumentDesign extends Model
{
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['business_line' => BusinessLine::class, 'revision' => 'integer', 'furniture_style' => 'array', 'quote_blocks' => 'array', 'invoice_blocks' => 'array'];
    }
}
