<?php

declare(strict_types=1);

namespace App\Domain\Website\Builder;

use App\Domain\Website\Models\Media;
use App\Domain\Website\Models\Page;
use Illuminate\Support\Str;

/**
 * Publishes an article or a study into its page file.
 *
 * The Blog and Portfolio screens edit rows, and rows stopped being a page when the renderer was retired
 * (step 8) — the "New post" button produced a 404 and an edit changed nothing a visitor could see
 * (docs/audit/20). This writes what the editor saved into `public/insights/<slug>/index.html` (or
 * `public/projects/<slug>/index.html`) in the v1 markup, using an existing page of the same kind as the
 * shell so the header, footer, styles and scripts stay exactly the design's own.
 *
 * Only the head's page metadata and `<main>` are replaced. If the code that publishes is ever removed, the
 * files it wrote stay — the site is still the files.
 */
final class ArticleFile
{
    /** Editor type => the folder its pages live in. */
    public const array FOLDERS = ['post' => 'insights', 'study' => 'projects'];

    /** The site folder — public/ in production, a temporary folder in tests. */
    public static function root(): string
    {
        return BuilderFile::root();
    }

    public static function pathFor(Page $page, string $type): ?string
    {
        return self::pathForSlug((string) $page->slug, $type);
    }

    public static function pathForSlug(string $slug, string $type): ?string
    {
        $folder = self::FOLDERS[$type] ?? null;

        if ($folder === null) {
            return null;
        }

        $short = trim((string) preg_replace('#^/?(insights|projects)/#', '', $slug), '/');

        if ($short === '') {
            return null;
        }

        return $folder.'/'.$short.'/index.html';
    }

    /**
     * Take a page off the site: the file moves to storage instead of being deleted, so a draft can be brought
     * back and nothing the design shipped is ever lost. Returns the file's old path.
     */
    public static function retire(Page $page, string $type): ?string
    {
        $path = self::pathFor($page, $type);

        if ($path === null || ! is_file(self::root().'/'.$path)) {
            return null;
        }

        $keep = storage_path('app/studio-retired/'.dirname($path));

        if (! is_dir($keep)) {
            mkdir($keep, 0o775, true);
        }

        $name = basename(dirname($path)).'-'.now()->format('Ymd-His').'.html';
        rename(self::root().'/'.$path, $keep.'/'.$name);

        // The folder only existed for this page; a leftover empty one would look like a page to the builder.
        @rmdir(dirname(self::root().'/'.$path));

        return $path;
    }

    /**
     * Write the page as a file. Returns its public-relative path, or null when there is nothing to publish.
     */
    public static function publish(Page $page, string $type): ?string
    {
        $path = self::pathFor($page, $type);

        if ($path === null) {
            return null;
        }

        $shell = is_file(self::root().'/'.$path) ? self::root().'/'.$path : self::shell($type);

        if ($shell === null) {
            return null;
        }

        $html = (string) file_get_contents($shell);
        $url = rtrim((string) config('woodex.public_site_url'), '/').'/'.dirname($path).'/';

        BuilderFile::write($path, self::render($html, $page, $type, $url));

        return $path;
    }

    /** The first page file of the same kind, used as the chrome for a page that has no file yet. */
    private static function shell(string $type): ?string
    {
        $folder = self::FOLDERS[$type] ?? null;

        if ($folder === null) {
            return null;
        }

        $files = glob(self::root().'/'.$folder.'/*/index.html') ?: [];
        sort($files);

        return $files[0] ?? null;
    }

    private static function render(string $shell, Page $page, string $type, string $url): string
    {
        $data = self::payload($page);
        $title = (string) ($page->seo['title'] ?? $page->title);
        $description = (string) ($page->seo['description'] ?? $page->excerpt ?? '');
        $hero = (string) ($data['hero']['src'] ?? '');

        $shellUrl = self::canonical($shell);
        $html = $shellUrl !== '' ? str_replace($shellUrl, $url, $shell) : $shell;

        $html = self::replaceTag($html, 'title', $title);
        $html = self::replaceMeta($html, 'name', 'description', $description);
        $html = self::replaceMeta($html, 'property', 'og:title', $title);
        $html = self::replaceMeta($html, 'property', 'og:description', $description);
        $html = self::replaceMeta($html, 'name', 'twitter:title', $title);
        $html = self::replaceMeta($html, 'name', 'twitter:description', $description);

        // The page's own address, not the shell's: a shared link that points at the template sends the reader
        // to someone else's article. og:type is the design's own choice per kind — insights are articles,
        // projects are website pages — so a shell that has it keeps it and a new page follows the same rule.
        $html = self::ensureMeta($html, 'property', 'og:url', $url);
        $html = self::ensureMeta($html, 'property', 'og:type', $type === 'post' ? 'article' : 'website');

        if ($hero !== '') {
            $absolute = str_starts_with($hero, 'http') ? $hero : rtrim((string) config('woodex.public_site_url'), '/').'/'.ltrim($hero, '/');
            $html = self::replaceMeta($html, 'property', 'og:image', $absolute);
            $html = self::replaceMeta($html, 'name', 'twitter:image', $absolute);
        }

        return self::replaceMain($html, self::main($page, $type, $data));
    }

    private static function canonical(string $html): string
    {
        return preg_match('~<link rel="canonical" href="([^"]+)"~', $html, $match) === 1 ? $match[1] : '';
    }

    /**
     * Replace a meta tag, or add it to the head when the shell has none. `replaceMeta` alone leaves a page
     * without og:url (or og:type) when its shell happens not to carry the tag, and a shared link then has
     * nothing to name the page with.
     */
    private static function ensureMeta(string $html, string $attribute, string $key, string $value): string
    {
        $tag = '<meta '.$attribute.'="'.$key.'" content="'.e($value).'" />';

        if (preg_match('~<meta\s+'.$attribute.'="'.preg_quote($key, '~').'"\s+content="[^"]*"\s*/?>~i', $html) === 1) {
            return (string) preg_replace('~<meta\s+'.$attribute.'="'.preg_quote($key, '~').'"\s+content="[^"]*"\s*/?>~i', $tag, $html, 1);
        }

        return (string) preg_replace('~</head>~i', $tag.'</head>', $html, 1);
    }

    private static function replaceTag(string $html, string $tag, string $value): string
    {
        $escaped = e($value);

        return (string) preg_replace('~<'.$tag.'>.*?</'.$tag.'>~s', '<'.$tag.'>'.$escaped.'</'.$tag.'>', $html, 1);
    }

    private static function replaceMeta(string $html, string $attribute, string $key, string $value): string
    {
        $pattern = '~<meta\s+'.$attribute.'="'.preg_quote($key, '~').'"\s+content="[^"]*"\s*/?>~i';

        if (preg_match($pattern, $html) !== 1) {
            return $html;
        }

        return (string) preg_replace_callback(
            $pattern,
            static fn (): string => '<meta '.$attribute.'="'.$key.'" content="'.e($value).'" />',
            $html,
            1,
        );
    }

    private static function replaceMain(string $html, string $main): string
    {
        if (preg_match('~<main\b[^>]*>.*?</main>~s', $html) === 1) {
            return (string) preg_replace('~<main\b[^>]*>.*?</main>~s', $main, $html, 1);
        }

        return str_replace('</body>', $main."\n</body>", $html);
    }

    /**
     * The article as the editor holds it: the studio JSON when it was written there, otherwise read out of the
     * hero/text/intro blocks the imported pages already have (the same reading the editor itself uses).
     *
     * @return array<string, mixed>
     */
    public static function payload(Page $page): array
    {
        $studio = $page->blocks()->where('type', 'text')->where('block_key', 'like', 'bstu%')->first();

        if ($studio !== null && is_array($studio->data['studio'] ?? null)) {
            /** @var array<string, mixed> $data */
            $data = $studio->data['studio'];

            return $data;
        }

        $hero = $page->blocks()->where('type', 'hero')->orderBy('position')->first();
        $facts = $page->blocks()->where('type', 'intro')->orderBy('position')->first();
        $blocks = [];

        foreach ($page->blocks()->orderBy('position')->get() as $block) {
            if (in_array($block->type->value, ['hero', 'intro', 'form', 'faq', 'related'], true)) {
                continue;
            }

            $heading = trim((string) ($block->data['heading'] ?? ''));
            $body = trim((string) ($block->data['body'] ?? $block->data['text'] ?? ''));

            if ($heading !== '') {
                $blocks[] = ['t' => 'h', 'text' => $heading];
            }

            foreach (preg_split('/\n\s*\n/', $body) ?: [] as $paragraph) {
                if (trim($paragraph) !== '') {
                    $blocks[] = ['t' => 'p', 'text' => trim($paragraph)];
                }
            }
        }

        $meta = [];

        foreach ((array) ($facts?->data['facts'] ?? []) as $fact) {
            if (is_array($fact) && ($fact['label'] ?? '') !== '') {
                $meta[] = ['k' => (string) $fact['label'], 'v' => (string) ($fact['value'] ?? '')];
            }
        }

        $heroId = (int) ($hero?->data['image_id'] ?? 0);

        return [
            'kicker' => (string) ($hero?->data['eyebrow'] ?? ''),
            'dek' => (string) ($page->excerpt ?? $hero?->data['text'] ?? ''),
            'hero' => ['src' => $heroId > 0 ? self::mediaUrl($heroId) : '', 'alt' => ''],
            'meta' => $meta,
            'blocks' => $blocks,
            'summary' => [],
            'faqs' => [],
            'quote' => '',
            'related' => [],
            'card' => ['src' => (int) $page->featured_image_id > 0 ? self::mediaUrl((int) $page->featured_image_id) : ''],
        ];
    }

    private static function mediaUrl(int $id): string
    {
        return Media::query()->find($id)?->url() ?? '';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function main(Page $page, string $type, array $data): string
    {
        $kicker = trim((string) ($data['kicker'] ?? ''));
        $dek = trim((string) ($data['dek'] ?? ''));
        $hero = (string) ($data['hero']['src'] ?? '');
        $heroAlt = trim((string) ($data['hero']['alt'] ?? '')) ?: (string) $page->title;
        $meta = (array) ($data['meta'] ?? []);
        $blocks = array_values(array_filter((array) ($data['blocks'] ?? []), static fn ($b): bool => is_array($b)));

        $html = '<main id="main-content">'."\n";

        // Hero.
        $html .= '    <section class="dx-hero'.($hero !== '' ? ' dx-hero--image' : '').'" aria-labelledby="dx-title">'."\n";

        if ($hero !== '') {
            $html .= '      '.self::heroImage($hero, $heroAlt)."\n";
        }

        $html .= "      <div class=\"dx-wrap dx-hero-inner\">\n";
        if ($kicker !== '') {
            $html .= '        <p class="dx-kicker">'.e($kicker)."</p>\n";
        }
        $html .= '        <h1 id="dx-title">'.e((string) $page->title)."</h1>\n";
        if ($dek !== '') {
            $html .= '        <p class="dx-dek">'.e($dek)."</p>\n";
        }
        if ($meta !== []) {
            $html .= "        <dl class=\"dx-meta\">\n";
            foreach ($meta as $fact) {
                $html .= '          <div class="dx-meta-item"><dt>'.e((string) ($fact['k'] ?? '')).'</dt><dd>'.e((string) ($fact['v'] ?? ''))."</dd></div>\n";
            }
            $html .= "        </dl>\n";
        }
        $html .= "      </div>\n    </section>\n";

        // Body.
        $html .= "    <section class=\"dx-body\">\n      <div class=\"dx-wrap dx-body-grid\">\n        <div class=\"dx-main\">\n";

        $html .= self::summary((array) ($data['summary'] ?? []));
        $html .= self::toc($blocks);
        $html .= self::sections($blocks);
        $html .= self::faqs((array) ($data['faqs'] ?? []));

        $html .= "        </div>\n      </div>\n    </section>\n";

        // Quote, related and the contact band: the three blocks every article ends with.
        $quote = trim((string) ($data['quote'] ?? ''));
        if ($quote !== '') {
            $html .= '    <section class="dx-quote"><div class="dx-wrap"><blockquote data-in-reveal>'.self::inline($quote)."</blockquote></div></section>\n";
        }

        $html .= self::related($page, $type);
        $html .= self::cta($hero !== '' ? $hero : '', $heroAlt);

        return $html.'</main>';
    }

    private static function heroImage(string $src, string $alt): string
    {
        $srcset = self::srcset($src);
        $size = self::size($src);

        return '<img sizes="100vw"'.($srcset !== '' ? ' srcset="'.$srcset.'"' : '')
            .' fetchpriority="high" class="dx-hero-bg" src="'.e($src).'" alt="'.e($alt).'" aria-hidden="true"'
            .' width="'.$size[0].'" height="'.$size[1].'" loading="eager" decoding="async">';
    }

    /** `img-abc.webp` and its `-480`/`-960` copies become one srcset — only the files that exist. */
    private static function srcset(string $src): string
    {
        if (! str_starts_with($src, '/assets/img/')) {
            return '';
        }

        $extension = '.'.pathinfo((string) parse_url($src, PHP_URL_PATH), PATHINFO_EXTENSION);

        if ($extension === '.') {
            return '';
        }

        $base = substr($src, 0, -strlen($extension));
        $candidates = [];

        foreach ([480, 960, 1920] as $width) {
            $candidate = $width === 1920 ? $base.$extension : $base.'-'.$width.$extension;

            if (is_file(self::root().'/'.ltrim($candidate, '/'))) {
                $candidates[] = $candidate.' '.$width.'w';
            }
        }

        return implode(', ', $candidates);
    }

    /** @return array{0: int, 1: int} */
    private static function size(string $src): array
    {
        $file = self::root().'/'.ltrim(parse_url($src, PHP_URL_PATH) ?: $src, '/');

        if (is_file($file)) {
            $size = @getimagesize($file);

            if (is_array($size)) {
                return [(int) $size[0], (int) $size[1]];
            }
        }

        return [1920, 1280];
    }

    /**
     * @param  array<int, mixed>  $summary
     */
    private static function summary(array $summary): string
    {
        $lines = [];

        foreach ($summary as $item) {
            $strong = '';
            $text = '';

            if (is_array($item)) {
                $strong = trim((string) ($item['b'] ?? $item['strong'] ?? $item['label'] ?? ''));
                $text = trim((string) ($item['t'] ?? $item['text'] ?? $item['value'] ?? ''));
            } elseif (is_string($item)) {
                $text = trim($item);
            }

            if ($text !== '') {
                $lines[] = '            <li>'.($strong !== '' ? '<strong>'.e($strong).':</strong> ' : '').self::inline($text).'</li>';
            }
        }

        if ($lines === []) {
            return '';
        }

        return "          <aside class=\"wx-short wx-short-post\" aria-label=\"In short\"><div class=\"wx-short-in\"><p class=\"wx-short-k\">In short</p><ul>\n"
            .implode("\n", $lines)."\n            </ul></div></aside>\n";
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private static function toc(array $blocks): string
    {
        $items = [];

        foreach ($blocks as $index => $block) {
            if (($block['t'] ?? '') !== 'h') {
                continue;
            }

            $text = trim((string) ($block['text'] ?? ''));

            if ($text !== '') {
                $items[] = '            <li><a href="#'.self::anchor($text, count($items)).'">'.self::inline($text).'</a></li>';
            }
        }

        if (count($items) < 2) {
            return '';
        }

        return "          <nav class=\"dx-toc\" aria-label=\"On this page\" data-in-reveal><p>On this page</p><ol>\n"
            .implode("\n", $items)."\n          </ol></nav>\n";
    }

    /**
     * One `<div data-in-reveal>` per heading, holding what follows it until the next heading.
     *
     * @param  array<int, array<string, mixed>>  $blocks
     */
    private static function sections(array $blocks): string
    {
        $html = '';
        $open = false;
        $headings = 0;

        foreach ($blocks as $block) {
            $type = (string) ($block['t'] ?? '');
            $text = (string) ($block['text'] ?? '');

            if ($type === 'h') {
                if ($open) {
                    $html .= "</div>\n";
                }

                $html .= '          <div data-in-reveal><h2 id="'.self::anchor($text, $headings).'">'.self::inline($text)."</h2>\n";
                $open = true;
                $headings++;

                continue;
            }

            if (! $open) {
                $html .= "          <div data-in-reveal>\n";
                $open = true;
            }

            $html .= self::block($block);
        }

        if ($open) {
            $html .= "</div>\n";
        }

        return $html;
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private static function block(array $block): string
    {
        $type = (string) ($block['t'] ?? 'p');
        $text = (string) ($block['text'] ?? '');

        return match ($type) {
            'p' => $text === '' ? '' : '<p>'.self::inline($text)."</p>\n",
            'img' => self::image($block),
            'list' => self::list($block),
            'table' => self::table($text),
            'quote' => '<blockquote class="dx-quote-inline">'.self::inline($text)."</blockquote>\n",
            'cta' => self::ctaBlock($block),
            default => $text === '' ? '' : '<p>'.self::inline($text)."</p>\n",
        };
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private static function image(array $block): string
    {
        $src = (string) ($block['src'] ?? '');

        if ($src === '') {
            return '';
        }

        $alt = (string) ($block['alt'] ?? '');
        $caption = (string) ($block['caption'] ?? '');
        $srcset = self::srcset($src);
        $size = self::size($src);

        return '<figure class="in-image" data-in-reveal><img sizes="(max-width: 768px) 100vw, 50vw"'
            .($srcset !== '' ? ' srcset="'.$srcset.'"' : '')
            .' src="'.e($src).'" alt="'.e($alt).'" width="'.$size[0].'" height="'.$size[1].'" loading="lazy" decoding="async">'
            .($caption !== '' ? '<figcaption>'.self::inline($caption).'</figcaption>' : '')
            ."</figure>\n";
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private static function list(array $block): string
    {
        $items = (array) ($block['items'] ?? []);
        $ordered = in_array(($block['style'] ?? 'ul'), ['ol', 'ordered', 'number'], true);

        if ($items === []) {
            foreach (preg_split('/\R/', (string) ($block['text'] ?? '')) ?: [] as $line) {
                $line = trim((string) preg_replace('/^(\d+[.)]|[-*•])\s*/', '', trim($line)));

                if ($line !== '') {
                    $items[] = $line;
                }
            }
        }

        if ($items === []) {
            return '';
        }

        $tag = $ordered ? 'ol' : 'ul';
        $html = '<'.$tag.">\n";

        foreach ($items as $item) {
            $html .= '  <li>'.self::inline((string) (is_array($item) ? ($item['text'] ?? '') : $item))."</li>\n";
        }

        return $html.'</'.$tag.">\n";
    }

    /** Pipe tables (`| a | b |`) as the editor keeps them. */
    private static function table(string $text): string
    {
        $rows = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || preg_match('/^\|?[\s:|-]+\|?$/', $line) === 1) {
                continue;
            }

            $cells = array_map('trim', explode('|', trim($line, '| ')));
            $rows[] = $cells;
        }

        if ($rows === []) {
            return '';
        }

        $head = array_shift($rows);
        $html = "<div class=\"wx-tbl\"><table>\n<thead><tr>";

        foreach ($head as $cell) {
            $html .= '<th>'.self::inline($cell).'</th>';
        }

        $html .= "</tr></thead>\n<tbody>\n";

        foreach ($rows as $row) {
            $html .= '<tr>';

            foreach ($row as $cell) {
                $html .= '<td>'.self::inline($cell).'</td>';
            }

            $html .= "</tr>\n";
        }

        return $html."</tbody></table></div>\n";
    }

    /**
     * @param  array<string, mixed>  $block
     */
    private static function ctaBlock(array $block): string
    {
        $title = trim((string) ($block['title'] ?? ''));
        $text = trim((string) ($block['text'] ?? ''));
        $href = trim((string) ($block['href'] ?? '/contact/'));
        $label = trim((string) ($block['label'] ?? 'Book a consultation'));

        return '<aside class="wx-cta">'
            .($title !== '' ? '<h3>'.self::inline($title).'</h3>' : '')
            .($text !== '' ? '<p>'.self::inline($text).'</p>' : '')
            .'<a href="'.e($href).'">'.self::inline($label)." →</a></aside>\n";
    }

    /**
     * @param  array<int, mixed>  $faqs
     */
    private static function faqs(array $faqs): string
    {
        $items = [];

        foreach ($faqs as $index => $faq) {
            $question = trim((string) (is_array($faq) ? ($faq['q'] ?? $faq['question'] ?? '') : ''));
            $answer = trim((string) (is_array($faq) ? ($faq['a'] ?? $faq['answer'] ?? '') : ''));

            if ($question === '' || $answer === '') {
                continue;
            }

            $items[] = '      <div class="in-faq-item"><button class="in-faq-q" type="button" aria-expanded="'.($index === 0 ? 'true' : 'false').'">'
                .'<span>'.self::inline($question).'</span><span class="plus">+</span></button>'
                .'<div class="in-faq-a"><div><p>'.self::inline($answer).'</p></div></div></div>';
        }

        if ($items === []) {
            return '';
        }

        return "          <div data-in-reveal>\n"
            ."            <header data-in-reveal><p class=\"in-label\">Questions</p><h2 id=\"s-faq\">Asked often, answered plainly.</h2></header>\n"
            ."            <div class=\"in-faq-list\">\n".implode("\n", $items)."\n            </div>\n          </div>\n";
    }

    /**
     * The hero an existing page file already shows; used for related cards, which the design gives an image.
     *
     * @return array{src: string, alt: string}
     */
    private static function heroOf(Page $page, string $type): array
    {
        $path = self::pathFor($page, $type);

        if ($path !== null && is_file(self::root().'/'.$path)) {
            $html = (string) file_get_contents(self::root().'/'.$path);

            if (preg_match('~<img[^>]*class="dx-hero-bg"[^>]*>~', $html, $tag) === 1) {
                $src = preg_match('~src="([^"]+)"~', $tag[0], $match) === 1 ? $match[1] : '';
                $alt = preg_match('~alt="([^"]*)"~', $tag[0], $match) === 1 ? html_entity_decode($match[1], ENT_QUOTES) : '';

                if ($src !== '') {
                    return ['src' => $src, 'alt' => $alt !== '' ? $alt : (string) $page->title];
                }
            }
        }

        $id = (int) $page->featured_image_id;

        return ['src' => $id > 0 ? self::mediaUrl($id) : '', 'alt' => (string) $page->title];
    }

    /** Three other published pages of the same kind, newest first. */
    private static function related(Page $page, string $type): string
    {
        $folder = self::FOLDERS[$type] ?? null;

        if ($folder === null) {
            return '';
        }

        $others = Page::query()
            ->where('type', $page->type)
            ->whereKeyNot($page->getKey())
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        $cards = [];

        foreach ($others as $other) {
            $path = self::pathFor($other, $type);

            if ($path === null || ! is_file(self::root().'/'.$path)) {
                continue;
            }

            $url = '/'.dirname($path).'/';
            $hero = self::heroOf($other, $type);
            $image = (int) $other->featured_image_id > 0 ? self::mediaUrl((int) $other->featured_image_id) : $hero['src'];
            $figure = '';

            if ($image !== '') {
                $figure = '<figure><img sizes="(max-width: 768px) 100vw, 50vw" src="'.e($image).'" alt="'.e($hero['alt']).'" loading="lazy" decoding="async"></figure>';
            }

            $cards[] = '        <a class="dx-card" href="'.e($url).'" data-in-reveal>'."\n          ".$figure
                .'<h3>'.e((string) $other->title).'</h3>'
                .'<p>'.e((string) ($other->excerpt ?? '')).'</p>'
                .'<span class="dx-more">Read the article</span>'."\n        </a>";
        }

        if ($cards === []) {
            return '';
        }

        return "    <section class=\"dx-related\">\n      <div class=\"dx-wrap\">\n"
            ."        <header data-in-reveal><p class=\"dx-kicker\">Keep looking</p><h2>Related reading.</h2></header>\n"
            ."        <div class=\"dx-cards\">\n".implode("\n", $cards)."\n        </div>\n      </div>\n    </section>\n";
    }

    private static function cta(string $image, string $alt): string
    {
        $figure = '';

        if ($image !== '') {
            $size = self::size($image);

            $figure = '<figure class="in-image" data-in-reveal=""><img sizes="(max-width: 768px) 100vw, 50vw"'
                .' src="'.e($image).'" alt="'.e($alt).'" width="'.$size[0].'" height="'.$size[1].'" loading="lazy" decoding="async"></figure>';
        }

        return <<<HTML
    <section class="in-section" id="cta"><div class="in-wrap in-cta-grid">
          <div class="in-cta-copy" data-in-reveal="">
            <p class="in-label">Start your project</p>
            <h2>Ready to start your project?</h2>
            <div class="in-cta-meta"><address>M-71, Zainab Tower, Model Town Link Road, Lahore</address><a href="tel:+923224000768">+92 322 4000768</a><a href="mailto:info@woodex.com.pk">info@woodex.com.pk</a><p><strong>Mon–Sat 9:30–6:30</strong> · Sunday closed</p></div>
            <div class="actions"><a class="btn" href="mailto:info@woodex.com.pk?subject=Start%20a%20Woodex%20project">Email the studio</a><a class="btn btn-light" href="tel:+923224000768">Call +92 322 4000768</a></div>
          </div>
          {$figure}
        </div></section>

HTML;
    }

    private static function anchor(string $text, int $index): string
    {
        return 's-'.($index + 1).'-'.(Str::slug($text) ?: 'section');
    }

    /** The small markdown the editor keeps: **bold**, *italic* and [text](link), HTML-escaped first. */
    private static function inline(string $text): string
    {
        $html = e($text);

        $html = (string) preg_replace('~\*\*(.+?)\*\*~s', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('~(?<!\*)\*([^*\n]+)\*(?!\*)~', '<em>$1</em>', $html);
        $html = (string) preg_replace('~\[([^\]]+)\]\((https?://[^)\s]+|/[^)\s]*)\)~', '<a href="$2">$1</a>', $html);

        return $html;
    }
}
