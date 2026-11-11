<?php

declare(strict_types=1);

namespace App\Domain\Website\Models;

use App\Domain\Website\Enums\PageStatus;
use App\Domain\Website\Enums\PageType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\Website\PageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A public page (docs/09 §2.1, D-67). The slug always carries its trailing slash, which is the canonical form.
 *
 * @property int $id
 * @property PageType $type
 * @property string $slug
 * @property ?int $parent_id
 * @property string $title
 * @property ?string $excerpt
 * @property PageStatus $status
 * @property ?CarbonImmutable $published_at
 * @property ?string $template
 * @property string|null $canvas_html
 * @property ?int $author_id
 * @property array<string, mixed>|null $seo
 * @property ?string $schema_type
 * @property ?int $featured_image_id
 * @property int $sort
 */
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    use SoftDeletes;

    protected static function newFactory(): PageFactory
    {
        return PageFactory::new();
    }

    protected $fillable = [
        'type', 'slug', 'parent_id', 'title', 'excerpt', 'status', 'published_at', 'template', 'canvas_html',
        'author_id', 'seo', 'schema_type', 'featured_image_id', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'type' => PageType::class,
            'status' => PageStatus::class,
            'seo' => 'array',
            'published_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<PageBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(PageBlock::class)->orderBy('position');
    }

    /** @return HasMany<PageVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(PageVersion::class)->latest('id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<Media, $this> */
    public function featuredImage(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'featured_image_id');
    }

    public function isPublished(): bool
    {
        return $this->status === PageStatus::Published
            && ($this->published_at === null || $this->published_at->isPast());
    }

    /** The slug as a URL path: "/" for the home page, otherwise the slug. */
    public function path(): string
    {
        return $this->slug === '/' ? '/' : $this->slug;
    }

    /**
     * The absolute canonical URL. Built by hand: url() trims the trailing slash, and the slash is what makes a
     * URL canonical (docs/09 §11.1).
     */
    public function canonical(): string
    {
        return self::urlFor($this->path());
    }

    /** @see canonical() */
    public static function urlFor(string $path): string
    {
        return rtrim(url('/'), '/').'/'.ltrim($path, '/');
    }

    /** SEO title with the site name appended, as the editor and the head partial use it. */
    public function seoTitle(string $siteName): string
    {
        return (string) ($this->seo['title'] ?? $this->title).' · '.$siteName;
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PageStatus::Published->value)
            ->where(fn (Builder $q): Builder => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    /**
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public function scopeOfType(Builder $query, PageType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * Published pages of a type, newest first, for menus and "related" sections.
     *
     * @return Collection<int, Page>
     */
    public static function publishedOfType(PageType $type): Collection
    {
        return self::query()->published()->ofType($type)->orderBy('sort')->orderBy('title')->get();
    }

    /** The canonical slug for a path: always with a trailing slash, and "/" for the home page. */
    public static function normalizeSlug(string $path): string
    {
        $path = '/'.trim($path, '/');

        return $path === '/' ? '/' : $path.'/';
    }
}
