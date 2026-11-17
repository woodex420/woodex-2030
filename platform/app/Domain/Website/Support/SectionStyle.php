<?php

declare(strict_types=1);

namespace App\Domain\Website\Support;

/**
 * Section settings (19 Oct, builder parity with admin v2.1 pro): the handful of choices an editor makes
 * about the *wrapper* of one section — its background, the space above and below it, how wide its
 * content may grow and whether it is centred — plus the label the section is known by in the editor.
 *
 * Kept apart from the section's data on purpose: a block's data is what the section says (its text,
 * photos, rows), while these are how it is framed. Changing the background of a section therefore
 * never touches its content, and a section that keeps no settings renders exactly as designed.
 *
 * This class is the single place where the values are validated and turned into CSS class names, so
 * the editor, the saver and the page template cannot drift apart.
 */
final class SectionStyle
{
    /** @var array<string, string> */
    public const array BACKGROUNDS = [
        '' => 'As designed',
        'white' => 'White',
        'light' => 'Warm light',
        'beige' => 'Sand',
        'navy' => 'Navy',
    ];

    /** @var array<string, string> */
    public const array SPACINGS = [
        '' => 'As designed',
        'compact' => 'Compact',
        'spacious' => 'Spacious',
        'flush' => 'No padding',
    ];

    /** @var array<string, string> */
    public const array WIDTHS = [
        '' => 'Boxed',
        'full' => 'Full width',
    ];

    /** @var array<string, string> */
    public const array ALIGNMENTS = [
        '' => 'As designed',
        'centre' => 'Centred',
    ];

    /** The three widths the editor offers, widest first. Desktop is the base the others inherit. */
    public const array DEVICES = ['desktop', 'tablet', 'mobile'];

    /** @var array<string, string> */
    public const array DEVICE_LABELS = [
        'desktop' => 'Desktop',
        'tablet' => 'Tablet · ≤1024px',
        'mobile' => 'Mobile · ≤640px',
    ];

    /** Spacing is written in pixels and kept within a sane band; empty means "as designed". */
    private const int MAX_SPACING = 200;

    /**
     * @return array{label: string, background: string, spacing: string, width: string, align: string, responsive: array<string, array{top: ?int, bottom: ?int, hidden: bool}>}
     */
    public static function defaults(): array
    {
        return [
            'label' => '',
            'background' => '',
            'spacing' => '',
            'width' => '',
            'align' => '',
            'responsive' => self::emptyResponsive(),
        ];
    }

    /**
     * @return array<string, array{top: ?int, bottom: ?int, hidden: bool}>
     */
    private static function emptyResponsive(): array
    {
        return [
            'desktop' => ['top' => null, 'bottom' => null, 'hidden' => false],
            'tablet' => ['top' => null, 'bottom' => null, 'hidden' => false],
            'mobile' => ['top' => null, 'bottom' => null, 'hidden' => false],
        ];
    }

    /**
     * Only known keys and known values reach the database — an unknown class posted by hand is
     * dropped, never printed into the page.
     *
     * @param  array<string, mixed>|null  $settings
     * @return array{label: string, background: string, spacing: string, width: string, align: string, responsive: array<string, array{top: ?int, bottom: ?int, hidden: bool}>}
     */
    public static function clean(?array $settings): array
    {
        $settings ??= [];
        $responsive = self::emptyResponsive();
        $rawResponsive = is_array($settings['responsive'] ?? null) ? $settings['responsive'] : [];

        foreach (self::DEVICES as $device) {
            $raw = is_array($rawResponsive[$device] ?? null) ? $rawResponsive[$device] : [];

            $responsive[$device] = [
                'top' => self::pixels($raw['top'] ?? null),
                'bottom' => self::pixels($raw['bottom'] ?? null),
                // The whole section cannot be hidden on desktop — the visible toggle is the switch there.
                'hidden' => $device !== 'desktop' && (bool) ($raw['hidden'] ?? false),
            ];
        }

        return [
            'label' => mb_substr(trim(strip_tags((string) ($settings['label'] ?? ''))), 0, 60),
            'background' => self::oneOf($settings['background'] ?? '', self::BACKGROUNDS),
            'spacing' => self::oneOf($settings['spacing'] ?? '', self::SPACINGS),
            'width' => self::oneOf($settings['width'] ?? '', self::WIDTHS),
            'align' => self::oneOf($settings['align'] ?? '', self::ALIGNMENTS),
            'responsive' => $responsive,
        ];
    }

    /**
     * The per-device CSS for one section, scoped to its block key. Empty when nothing was set, so a
     * page with no responsive choices adds no bytes. Values are integers and the key is sanitised,
     * so the result is safe to print raw in a <style> block.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public static function css(?array $settings, string $key): string
    {
        $key = (string) preg_replace('/[^A-Za-z0-9_-]/', '', $key);

        if ($key === '') {
            return '';
        }

        if (! config('website.builder.responsive_styles', true)) {
            return '';
        }

        $settings = self::clean($settings);
        $selector = '[data-wx-key="'.$key.'"]';
        $css = '';
        $base = self::declarations($settings['responsive']['desktop']);

        if ($base !== '') {
            $css .= $selector.'{'.$base.'}';
        }

        foreach (['tablet' => 1024, 'mobile' => 640] as $device => $width) {
            $declarations = self::declarations($settings['responsive'][$device]);

            if ($declarations !== '') {
                $css .= '@media (max-width:'.$width.'px){'.$selector.'{'.$declarations.'}}';
            }
        }

        return $css;
    }

    /** @param array{top: ?int, bottom: ?int, hidden: bool} $device */
    private static function declarations(array $device): string
    {
        $css = '';

        if ($device['hidden']) {
            $css .= 'display:none;';
        }

        if ($device['top'] !== null) {
            $css .= 'padding-top:'.$device['top'].'px;';
        }

        if ($device['bottom'] !== null) {
            $css .= 'padding-bottom:'.$device['bottom'].'px;';
        }

        return $css;
    }

    /** Empty, or a whole number of pixels inside the allowed band. */
    private static function pixels(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return max(0, min(self::MAX_SPACING, (int) $value));
    }

    /**
     * The wrapper's classes. `wx-sec` is always there so the builder's own preview styles have a hook.
     *
     * @param  array<string, mixed>|null  $settings
     * @return list<string>
     */
    public static function classes(?array $settings): array
    {
        $settings = self::clean($settings);

        return array_values(array_filter([
            'wx-sec',
            $settings['background'] !== '' ? 'wx-sec--bg-'.$settings['background'] : null,
            $settings['spacing'] !== '' ? 'wx-sec--sp-'.$settings['spacing'] : null,
            $settings['width'] !== '' ? 'wx-sec--'.$settings['width'] : null,
            $settings['align'] !== '' ? 'wx-sec--'.$settings['align'] : null,
        ]));
    }

    /**
     * The section's name in the editor: the label the studio gave it, or the type's own name.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public static function title(?array $settings, string $fallback): string
    {
        $label = trim((string) ($settings['label'] ?? ''));

        return $label !== '' ? $label : $fallback;
    }

    /**
     * @param  array<string, string>  $allowed
     */
    private static function oneOf(mixed $value, array $allowed): string
    {
        $value = (string) $value;

        return array_key_exists($value, $allowed) ? $value : '';
    }
}
