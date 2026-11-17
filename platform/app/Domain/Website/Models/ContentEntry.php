<?php

declare(strict_types=1);

namespace App\Domain\Website\Models;

use App\Domain\Website\Contracts\LibraryEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of the shared content library (V2-3 F6, docs/14 §5a): a question and its answer, a
 * client's review, or a client logo — written once, placed on any page by its FAQ, Reviews or
 * Clients section. `data` holds the row in the exact shape its section renders, so no translation
 * layer exists to drift.
 *
 * @property int $id
 * @property string $kind
 * @property array<string, mixed> $data
 * @property int $position
 */
class ContentEntry extends Model implements LibraryEntry
{
    public const string FAQ = 'faq';

    public const string TESTIMONIAL = 'testimonial';

    public const string LOGO = 'logo';

    public const array KINDS = [self::FAQ, self::TESTIMONIAL, self::LOGO];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * @param  Builder<ContentEntry>  $query
     * @return Builder<ContentEntry>
     */
    public function scopeOfKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    public function entryId(): int
    {
        return (int) $this->id;
    }

    /**
     * What the entry is called in lists: the question, the client's name, or the logo's alt text.
     */
    public function label(): string
    {
        $data = (array) $this->data;

        return (string) ($data['question'] ?? $data['name'] ?? $data['alt'] ?? 'Untitled');
    }

    /**
     * The entry as its section renders it: logo entries carry a media id that becomes the row's id.
     *
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function toRow(): array
    {
        $data = (array) $this->data;

        if ($this->kind === self::LOGO) {
            return ['id' => $data['media_id'] ?? null, 'alt' => $data['alt'] ?? ''];
        }

        return $data;
    }
}
