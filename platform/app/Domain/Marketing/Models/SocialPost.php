<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

use App\Domain\Website\Models\Media;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One composition headed for the studio's social accounts (P7, SOC-005/006): the caption (English
 * and Urdu), the photographs from the shared media library, the link and first comment, and the
 * networks it targets. The walk is draft → in review → approved → scheduled → published, and an
 * editor's post needs someone else's approval before it can go anywhere.
 *
 * @property int $id
 * @property string $text
 * @property string|null $text_ur
 * @property string|null $link_url
 * @property string|null $first_comment
 * @property string|null $hashtags
 * @property list<int> $media_ids
 * @property list<string> $targets
 * @property string $status
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $published_at
 */
class SocialPost extends Model
{
    public const string DRAFT = 'draft';

    public const string IN_REVIEW = 'in_review';

    public const string APPROVED = 'approved';

    public const string SCHEDULED = 'scheduled';

    public const string PUBLISHED = 'published';

    public const string FAILED = 'failed';

    public const array STATUSES = [self::DRAFT, self::IN_REVIEW, self::APPROVED, self::SCHEDULED, self::PUBLISHED, self::FAILED];

    public const array NETWORKS = ['facebook', 'instagram', 'linkedin'];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'media_ids' => 'array',
            'targets' => 'array',
            'scheduled_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The photographs this post carries, from the shared media library (SOC-010).
     *
     * @return list<Media>
     */
    public function media(): array
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->media_ids)));

        return $ids === [] ? [] : Media::query()->whereIn('id', $ids)->get()->all();
    }
}
