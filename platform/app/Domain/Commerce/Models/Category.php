<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

final class Category extends Model
{
    use LogsActivity;

    protected $table = 'commerce_categories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['parent_id' => 'integer', 'position' => 'integer', 'is_active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('commerce')->logOnly(['name', 'slug', 'parent_id', 'description', 'position', 'is_active'])
            ->logOnlyDirty()->dontLogEmptyChanges();
    }
}
