<?php

declare(strict_types=1);

namespace App\Domain\Website\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Google PageSpeed run in the speed dashboard's history (V2-3 F9, docs/14 §5a): the page's URL,
 * the strategy, the score out of 100, and the highlights Google worded — paint times, page weight,
 * render-blocking, image formats, unused JavaScript. The history is what a page's speed is: a line
 * over time, not one number.
 *
 * @property int $id
 * @property string $url
 * @property string $strategy
 * @property int $score
 * @property array<string, string> $metrics
 */
class PageSpeedRun extends Model
{
    protected $table = 'pagespeed_runs';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'metrics' => 'array',
        ];
    }
}
