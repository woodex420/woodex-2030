<?php

declare(strict_types=1);

namespace App\Domain\Website\Studio;

use App\Domain\Crm\Models\Lead;
use App\Domain\Sales\Services\EstimatorRateBooks;
use App\Domain\System\Models\Automation;
use App\Domain\Website\Enums\PageType;
use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use App\Domain\Website\Models\PageVersion;
use App\Domain\Website\Services\Menus;
use App\Domain\Website\Services\PageCache;
use App\Domain\Website\Services\PageCopier;
use App\Models\User;
use App\Settings\NotificationSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The server behind the ported v2.1 admin (docs/audit/16.7, 16.8).
 *
 * The admin's modules were written against one seam — `api(action, data)` posting `{action, ...}` and
 * expecting `{ok: true, ...}` — so this class is that seam and nothing else: a table of actions, each
 * mapped onto the model or service that already owns the data. It does not introduce a second content
 * model, a second media table or a second permission check.
 *
 * An action that has not been ported yet answers `{ok: false, error}` with the screen's name, because the
 * admin renders that as a plain message. Nothing here may pretend to have saved.
 */
final class StudioApi
{
    /** Actions implemented so far, and the method that serves each one. */
    private const array ACTIONS = [
        'status' => 'status',
        'me' => 'me',
        'media_upload' => 'mediaUpload',
        // The article and study editor (docs/audit/16.8): the list, the form, save, the Live switch,
        // delete and publish. `bapi_*` are the builder's three: preview shell, and the HTML snapshot.
        'cms_list' => 'cmsList',
        'cms_get' => 'cmsGet',
        'cms_save' => 'cmsSave',
        'cms_status' => 'cmsStatus',
        'cms_delete' => 'cmsDelete',
        'cms_published' => 'cmsPublished',
        // The Service pages screen (docs/audit/16.8): the page list, its types, and the two ways a new
        // service page is made — from an existing layout, or by duplicating a row.
        'pages' => 'pagesList',
        'cms_page_kinds' => 'pageKinds',
        // City pages: a new city is a draft copied from a template city, one at a time or several at once.
        'cms_city_new' => 'cityNew',
        'cms_city_bulk' => 'cityBulk',
        // The Forms screen reads the enquiry forms the public site posts, and the alert routing that is
        // already configured for all of them (there is no per-form store in the OS — see the screen's note).
        'cms_forms' => 'formsList',
        // The Estimator screen edits the public rate book (a file) and mirrors the rates into
        // estimator_rates, which the bot and the staff estimate builder price from (docs/audit/16.14).
        'cms_estimator' => 'estimatorBook',
        'cms_estimator_save' => 'estimatorSave',
        'cms_estimator_tpl' => 'estimatorTemplate',
        'bapi_save' => 'builderSave',
        'bapi_page_new' => 'builderSave',
        'bapi_media' => 'builderMedia',
    ];

    /**
     * Actions the v2.1 admin had for screens Woodex OS 1 does not keep (steps 18 and 22): the screen's name,
     * and where that work is done now. Each one wrote a `pages` or `content_entries` row, and a row stopped
     * being the website when the renderer was retired (step 8) — the screen is behind the door, so the action
     * behind it refuses too, by name, rather than accept a write nothing a visitor can see will change.
     */
    private const array RETIRED = [
        'cms_page_new' => ['Service pages', 'All pages and Page builder'],
        'cms_page_duplicate' => ['Service pages', 'All pages and Page builder'],
        'cms_faqs' => ['FAQs', 'the FAQ section of the page, in Page builder'],
        'cms_faq_save' => ['FAQs', 'the FAQ section of the page, in Page builder'],
        'cms_faq_delete' => ['FAQs', 'the FAQ section of the page, in Page builder'],
        'cms_faq_order' => ['FAQs', 'the FAQ section of the page, in Page builder'],
        'cms_testimonials' => ['Testimonials', 'the reviews section of the page, in Page builder'],
        'cms_testimonial_save' => ['Testimonials', 'the reviews section of the page, in Page builder'],
        'cms_testimonial_delete' => ['Testimonials', 'the reviews section of the page, in Page builder'],
        'cms_testimonial_order' => ['Testimonials', 'the reviews section of the page, in Page builder'],
        'logos_get' => ['Client logos', 'the clients section of the home page, and Media'],
        'logos_save' => ['Client logos', 'the clients section of the home page, and Media'],
    ];

    public function __construct(private readonly User $user) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function call(string $action, array $payload): array
    {
        if ($action === '') {
            throw new RuntimeException('No action was sent.');
        }

        $retired = self::RETIRED[$action] ?? null;

        if ($retired !== null) {
            [$screen, $now] = $retired;

            throw new RuntimeException(
                "“{$screen}” is the old admin’s screen: it changed database rows, and the website is its page "
                ."files. That work is done in {$now} now."
            );
        }

        $method = self::ACTIONS[$action] ?? null;

        if ($method === null) {
            throw new RuntimeException("“{$action}” is not ported yet — this screen is still being moved from the v2.1 admin.");
        }

        /** @var array<string, mixed> $result */
        $result = $this->{$method}($payload);

        return $result;
    }

    /**
     * The signed-in staff member, in the shape the admin's shell expects.
     *
     * @return array{name: string, email: string, role: string, perms: list<string>}
     */
    public function user(): array
    {
        return [
            'name' => $this->user->name,
            'email' => $this->user->email,
            'role' => $this->studioRole(),
            'perms' => [],
        ];
    }

    /** The admin's shell asks whether anyone is signed in before it draws anything. The OS session decides. */
    /** @param array<string, mixed> $payload */
    private function content(array $payload): StudioContent
    {
        return new StudioContent($this->user);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function cmsList(array $payload): array
    {
        return $this->content($payload)->listing((string) ($payload['type'] ?? 'post'), (string) ($payload['q'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function cmsGet(array $payload): array
    {
        return $this->content($payload)->get((int) ($payload['id'] ?? 0), $this->typeOf($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function cmsSave(array $payload): array
    {
        return $this->content($payload)->save($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function cmsStatus(array $payload): array
    {
        return $this->content($payload)->setStatus((int) ($payload['id'] ?? 0), (string) ($payload['status'] ?? 'draft'), $this->typeOf($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function cmsDelete(array $payload): array
    {
        return $this->content($payload)->delete((int) ($payload['id'] ?? 0), $this->typeOf($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function cmsPublished(array $payload): array
    {
        return $this->content($payload)->publish((int) ($payload['id'] ?? 0), $this->typeOf($payload));
    }

    /**
     * The pages the Service pages screen manages: everything except the article/study editors and the
     * city drafts, which have their own screens. `path` keeps the v2.1 shape (`foo/index.html`) because
     * the screen's own filters and labels were written for it; nothing on this server uses it as a file.
     *
     * @param  array<string, mixed>  $payload
     * @return array{pages: list<array{id: int, path: string, title: string, url: string, kind: string, status: string}>}
     */
    private function pagesList(array $payload): array
    {
        $pages = Page::query()
            ->whereNotIn('type', [PageType::Insight, PageType::Project])
            ->orderBy('type')
            ->orderBy('title')
            ->get()
            ->map(fn (Page $page): array => [
                'id' => $page->id,
                'path' => $this->pathOf($page),
                'title' => $page->title,
                'url' => $page->slug,
                'kind' => $page->type->value,
                'status' => $page->status->value,
            ])
            ->values()
            ->all();

        return ['pages' => $pages];
    }

    /**
     * The type badge the screen shows per page (v2.1 asked `cms_page_kinds` and got a path → kind map).
     *
     * @param  array<string, mixed>  $payload
     * @return array{kinds: array<string, string>}
     */
    private function pageKinds(array $payload): array
    {
        $kinds = [];

        foreach (Page::query()->get() as $page) {
            $kinds[$this->pathOf($page)] = $page->type->value;
        }

        return ['kinds' => $kinds];
    }

    /** @return array{id: int, title: string, url: string, status: string} */
    private function pageItem(Page $page): array
    {
        return [
            'id' => $page->id,
            'title' => $page->title,
            'url' => $page->slug,
            'status' => $page->status->value,
        ];
    }

    /**
     * The enquiry forms the v1 pages post, with what has actually arrived for each: submissions, the last
     * one, and the field names the leads carry. Nothing is written here — alert routing is configured once
     * for every form in Settings and Automations, and per-form routing would need a table the OS does not
     * have (docs/audit/16.13).
     *
     * @param  array<string, mixed>  $payload
     * @return array{forms: list<array<string, mixed>>, alerts: array<string, mixed>}
     */
    private function formsList(array $payload): array
    {
        $labels = [
            'brief' => '3D brief',
            'contact' => 'Contact form',
            'website' => 'Website form',
            'estimator' => 'Estimator',
            'booking' => 'Booking request',
            'book-a-visit' => 'Book a visit',
            'whatsapp' => 'WhatsApp widget',
        ];

        $forms = [];

        foreach ($labels as $key => $label) {
            $query = Lead::query()->where('source_detail', $key);

            $total = (clone $query)->count();
            $month = (clone $query)->where('created_at', '>=', now()->subDays(30))->count();
            $last = (clone $query)->max('created_at');

            /** @var list<string> $fields */
            $fields = [];

            foreach ((clone $query)->latest('id')->limit(200)->get(['meta']) as $lead) {
                /** @var array<string, mixed> $meta */
                $meta = (array) $lead->meta;

                foreach (array_keys((array) ($meta['fields'] ?? [])) as $field) {
                    $fields[(string) $field] = true;
                }
            }

            $forms[] = [
                'key' => $key,
                'label' => $label,
                'total' => $total,
                'month' => $month,
                'last' => $last === null ? null : (string) $last,
                'fields' => array_slice(array_keys($fields), 0, 30),
            ];
        }

        return ['forms' => $forms, 'alerts' => $this->alertRouting()];
    }

    /**
     * The rate book the public estimator is reading right now, plus the saved snapshots.
     *
     * @param  array<string, mixed>  $payload
     * @return array{book: array<string, mixed>, templates: list<array{name: string, at: string, services: int}>, page: string}
     */
    private function estimatorBook(array $payload): array
    {
        return app(EstimatorRateBooks::class)->read($this->user);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function estimatorSave(array $payload): array
    {
        $input = $payload['book'] ?? $payload;

        return app(EstimatorRateBooks::class)->publish($this->user, is_array($input) ? $input : [], $payload['revision'] ?? null);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function estimatorTemplate(array $payload): array
    {
        return app(EstimatorRateBooks::class)->template($this->user, $payload);
    }

    /**
     * How the OS routes a new enquiry today. Read from the services that actually send it, so this screen
     * cannot drift from the behaviour it describes.
     *
     * @return array<string, mixed>
     */
    private function alertRouting(): array
    {
        try {
            $settings = app(NotificationSettings::class);

            return [
                'automation' => Automation::enabled('woodex:new-lead-alert'),
                'roles' => array_values((array) $settings->unassigned_lead_roles),
                'quiet' => [$settings->quiet_hours_start, $settings->quiet_hours_end],
                'telegram' => $settings->telegram_enabled,
            ];
        } catch (Throwable) {
            // Settings that have not been seeded yet must not break the screen.
            return ['automation' => null, 'roles' => [], 'quiet' => null, 'telegram' => null];
        }
    }

    /**
     * “New city”: a draft city page copied from a template city, with the template's city name swapped
     * for the new one (v2.1's cloneCity did the same against the HTML file).
     *
     * @param  array<string, mixed>  $payload
     * @return array{item: array{id: int, title: string, url: string, status: string}}
     */
    private function cityNew(array $payload): array
    {
        return ['item' => $this->createCity((string) ($payload['name'] ?? ''), (int) ($payload['from'] ?? 0))];
    }

    /**
     * “Bulk create”: one city per line, each a draft. A name that fails is reported and the rest continue.
     *
     * @param  array<string, mixed>  $payload
     * @return array{created: list<array{id: int, title: string, url: string, status: string}>, failed: list<array{name: string, error: string}>}
     */
    private function cityBulk(array $payload): array
    {
        $raw = $payload['names'] ?? [];
        $names = is_array($raw) ? $raw : preg_split('/\r\n|\r|\n|,/', (string) $raw);
        $from = (int) ($payload['from'] ?? 0);

        $created = [];
        $failed = [];

        foreach (array_slice(array_values(array_filter(array_map(
            fn ($name): string => trim((string) $name),
            is_array($names) ? $names : []
        ))), 0, 25) as $name) {
            try {
                $created[] = $this->createCity($name, $from);
            } catch (Throwable $exception) {
                $failed[] = ['name' => $name, 'error' => $exception->getMessage()];
            }
        }

        return ['created' => $created, 'failed' => $failed];
    }

    /**
     * @return array{id: int, title: string, url: string, status: string}
     */
    private function createCity(string $name, int $from): array
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));

        if ($name === '') {
            throw new RuntimeException('A city name is needed.');
        }

        $source = $from > 0
            ? Page::query()->find($from)
            : Page::query()->where('type', PageType::City)->orderBy('id')->first();

        if ($source === null || $source->type !== PageType::City) {
            throw new RuntimeException('There is no city page to copy from yet.');
        }

        $slug = Page::normalizeSlug('/'.Str::slug($name).'/');

        if (Page::query()->where('slug', $slug)->exists()) {
            throw new RuntimeException("“{$name}” already has a page.");
        }

        $page = app(PageCopier::class)->copy($source, $name, $slug);
        $this->swapCityName($page, Str::headline(trim($source->slug, '/')), $name);

        PageCache::invalidate($page);
        Menus::flush();

        return $this->pageItem($page);
    }

    /**
     * Replace the template city's name inside the copied copy — the words, never a path or an image id,
     * so a copied page cannot end up pointing at the template's media.
     */
    private function swapCityName(Page $page, string $from, string $to): void
    {
        if ($from === '' || $from === $to) {
            return;
        }

        $pattern = '/(?<![\w-])'.preg_quote($from, '/').'(?![\w-])/u';

        $swap = function (mixed $value) use (&$swap, $pattern, $to) {
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $value[$key] = $swap($item);
                }

                return $value;
            }

            if (! is_string($value) || $value === '') {
                return $value;
            }

            // Addresses, media references and ids are copied as they are.
            if (preg_match('#^(/|https?:|mailto:|tel:|\#)#', $value) || str_contains($value, '/media/')) {
                return $value;
            }

            return (string) preg_replace($pattern, $to, $value);
        };

        foreach ($page->blocks as $block) {
            /** @var array<string, mixed> $data */
            $data = (array) $block->data;
            $block->forceFill(['data' => $swap($data)])->save();
        }
    }

    /** The v2.1 address shape the screen's filters were written for. No file is read or written. */
    private function pathOf(Page $page): string
    {
        return $page->slug === '/' ? 'index.html' : trim($page->slug, '/').'/index.html';
    }

    /**
     * The builder's `save` / `page_new`: the built HTML is kept as a page version — the rollback the static
     * file used to give. The site itself is rendered by the OS, so nothing is written to a public folder.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function builderSave(array $payload): array
    {
        $path = trim((string) ($payload['path'] ?? $payload['folder'].'/'.$payload['slug'].'/index.html'), '/');
        $html = (string) ($payload['html'] ?? '');

        $page = Page::query()->where('slug', '/'.preg_replace('#/index\.html$#', '/', $path))->first();

        if ($page === null) {
            throw new RuntimeException('Publish the page from the editor instead — the site renders it from its blocks.');
        }

        PageVersion::query()->create([
            'page_id' => $page->id,
            'user_id' => $this->user->id,
            'data' => ['html' => $html, 'path' => $path],
        ]);

        return ['path' => $path, 'mtime' => time()];
    }

    /**
     * The builder's `media`: the image picker's grid. The library's own images only — a picker that offered
     * a picture the page cannot render would be a trap.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function builderMedia(array $payload): array
    {
        // A row whose file is gone (an interrupted import, a deleted upload) must not be offered: the page
        // would render a broken image and nobody would know why.
        return ['media' => Media::query()->orderByDesc('id')->limit(500)->get()
            ->filter(fn (Media $media): bool => Storage::disk($media->disk)->exists($media->path))
            ->map(fn (Media $media): string => $media->url())
            ->values()
            ->all()];
    }

    /**
     * The content type of a row: the payload's own, or read from the page when the screen only sends an id.
     *
     * @param  array<string, mixed>  $payload
     */
    private function typeOf(array $payload): string
    {
        $type = (string) ($payload['type'] ?? '');

        if ($type !== '') {
            return $type;
        }

        $page = Page::query()->find((int) ($payload['id'] ?? 0));

        return $page?->type === PageType::Project ? 'study' : 'post';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function status(array $payload): array
    {
        return ['user' => $this->user()];
    }

    /** @return array<string, mixed> */
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function me(array $payload): array
    {
        return ['token' => 'os-session', 'user' => $this->user()];
    }

    /**
     * One image, base64 (the admin sends files that way because the builder's uploader already did).
     *
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mediaUpload(array $payload): array
    {
        $raw = (string) ($payload['data'] ?? '');

        if ($raw === '' || strlen($raw) > 8_000_000) {
            throw new RuntimeException('That image is empty or larger than 5 MB.');
        }

        $binary = base64_decode($raw, true);

        if ($binary === false) {
            throw new RuntimeException('That upload was not an image.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'wx21');

        if ($temporary === false) {
            throw new RuntimeException('The upload could not be written.');
        }

        file_put_contents($temporary, $binary);

        $file = new UploadedFile($temporary, (string) ($payload['name'] ?? 'image.png'), null, null, true);

        if (! in_array($file->extension(), ['jpg', 'jpeg', 'png', 'webp'], true) || @getimagesize($temporary) === false) {
            @unlink($temporary);

            throw new RuntimeException('Only JPG, PNG or WebP images can be uploaded.');
        }

        $name = pathinfo((string) ($payload['name'] ?? 'image'), PATHINFO_FILENAME);
        $path = 'website/'.now()->format('Y/m').'/'.Str::slug($name).'-'.Str::lower(Str::random(6)).'.'.$file->extension();

        Storage::disk('public')->put($path, $binary);
        // Extension and contents were checked above; the size is a bonus for the library's columns.
        /** @var mixed $size */
        $size = @getimagesize($temporary);
        $width = is_array($size) ? (int) $size[0] : null;
        $height = is_array($size) ? (int) $size[1] : null;

        $media = Media::query()->create([
            'disk' => 'public',
            'path' => $path,
            'alt' => Str::headline($name),
            'title' => Str::headline($name),
            'width' => $width,
            'height' => $height,
            'folder' => 'website/'.now()->format('Y/m'),
            'uploaded_by' => $this->user->id,
        ]);

        @unlink($temporary);

        return ['url' => $media->url(), 'id' => $media->id];
    }

    /** The admin's role list, for its own labels. The real check is the permission on the route. */
    private function studioRole(): string
    {
        return $this->user->hasRole('owner') ? 'owner' : 'admin';
    }
}
