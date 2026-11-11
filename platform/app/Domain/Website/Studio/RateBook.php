<?php

declare(strict_types=1);

namespace App\Domain\Website\Studio;

use UnexpectedValueException;

/**
 * The public estimator's rate book (docs/audit/16.14).
 *
 * `/estimator/` is the v1 page and it does not read the database: it loads `assets/js/estimator-rates.js` and
 * builds the calculator from `window.WX_RATES`. That file is the live price list, so the admin has to edit the
 * file itself — and the same save mirrors the rates into `estimator_rates`, which is what the bot and the staff
 * estimate builder price from.
 *
 * The file is hand-written JavaScript (unquoted keys, trailing commas, a header comment). Reading it with
 * `json_decode()` fails, and rewriting it with a JSON encoder would reformat a file a person maintains. So this
 * class has a small string-aware scanner: it quotes bare keys and drops trailing commas and comments, then hands
 * the result to `json_decode()`. Writing goes the other way, back to the file's own style.
 */
final class RateBook
{
    /** The three finish levels the public calculator offers, in the order it offers them. */
    public const array FINISHES = ['essential', 'standard', 'premium'];

    /** Units the calculator understands: square feet, or a number of 3D views. */
    public const array UNITS = ['sqft', 'views'];

    private const string HEADER = "/* Woodex estimator rate book (PKR). Edited in Woodex OS → Website → Estimator;\n   the estimator and quotation builder both read from here. */\n";

    public static function path(): string
    {
        $configured = config('website.rate_book_path');

        return is_string($configured) && $configured !== ''
            ? $configured
            : public_path('assets/js/estimator-rates.js');
    }

    /**
     * The file as a PHP array: services, finishes, catalog, terms.
     *
     * @return array{services: list<mixed>, finishes: list<mixed>, catalog: list<mixed>, terms: list<mixed>}
     */
    public static function read(): array
    {
        $path = self::path();

        if (! is_file($path)) {
            throw new UnexpectedValueException('The estimator rate book is missing: '.$path);
        }

        $js = (string) file_get_contents($path);
        $data = self::decode($js);

        if (! is_array($data)) {
            throw new UnexpectedValueException('The estimator rate book is not an object.');
        }

        return [
            'services' => is_array($data['services'] ?? null) ? array_values($data['services']) : [],
            'finishes' => is_array($data['finishes'] ?? null) ? array_values($data['finishes']) : [],
            'catalog' => is_array($data['catalog'] ?? null) ? array_values($data['catalog']) : [],
            'terms' => is_array($data['terms'] ?? null) ? array_values($data['terms']) : [],
        ];
    }

    /**
     * @param  array{services?: array<int, mixed>, finishes?: array<int, mixed>, catalog?: array<int, mixed>, terms?: array<int, mixed>}  $book
     */
    public static function write(array $book): void
    {
        $path = self::path();
        $old = is_file($path) ? (string) file_get_contents($path) : '';
        $header = self::headerOf($old);

        $body = self::encode([
            'services' => array_values($book['services'] ?? []),
            'finishes' => array_values($book['finishes'] ?? []),
            'catalog' => array_values($book['catalog'] ?? []),
            'terms' => array_values($book['terms'] ?? []),
        ]);

        $tmp = $path.'.tmp';
        if (file_put_contents($tmp, $header.$body) === false) {
            throw new UnexpectedValueException('The estimator rate book could not be written: '.$path);
        }

        if (! rename($tmp, $path)) {
            @unlink($tmp);

            throw new UnexpectedValueException('The estimator rate book could not be replaced: '.$path);
        }
    }

    /**
     * Clean and check an edited book. Returns the book in canonical shape, with a key for every service.
     *
     * @param  array<string, mixed>  $input
     * @return array{services: list<array<string, mixed>>, finishes: list<array<string, string>>, catalog: list<mixed>, terms: list<string>}
     */
    public static function normalise(array $input): array
    {
        $services = $input['services'] ?? null;

        if (! is_array($services) || $services === []) {
            throw new UnexpectedValueException('The estimate needs at least one service.');
        }

        if (count($services) > 24) {
            throw new UnexpectedValueException('That is more than 24 services — the estimator page is not built for that many.');
        }

        $used = [];
        $clean = [];

        foreach (array_values($services) as $raw) {
            if (! is_array($raw)) {
                throw new UnexpectedValueException('A service was sent in the wrong shape.');
            }

            $label = trim((string) ($raw['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 80) {
                throw new UnexpectedValueException('Every service needs a name of at most 80 characters.');
            }

            $hint = trim((string) ($raw['hint'] ?? ''));
            if (mb_strlen($hint) > 200) {
                throw new UnexpectedValueException("“{$label}”: the short description is over 200 characters.");
            }

            $unit = (string) ($raw['unit'] ?? 'sqft');
            if (! in_array($unit, self::UNITS, true)) {
                throw new UnexpectedValueException("“{$label}”: the unit has to be square feet or 3D views.");
            }

            $unitLabel = trim((string) ($raw['unitLabel'] ?? ''));
            if ($unitLabel === '' || mb_strlen($unitLabel) > 60) {
                throw new UnexpectedValueException("“{$label}”: the quantity label is required (max 60 characters).");
            }

            $rates = is_array($raw['rates'] ?? null) ? $raw['rates'] : [];
            $cleanRates = [];

            foreach (self::FINISHES as $finish) {
                $value = $rates[$finish] ?? null;

                if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > 10000000 || floor((float) $value) !== (float) $value) {
                    throw new UnexpectedValueException("“{$label}”: the {$finish} rate is missing.");
                }

                $cleanRates[$finish] = max(0, (int) round((float) $value));
            }

            if ($cleanRates['essential'] > $cleanRates['standard'] || $cleanRates['standard'] > $cleanRates['premium']) {
                throw new UnexpectedValueException("“{$label}”: the rates have to climb — Essential ≤ Standard ≤ Premium.");
            }

            $key = trim((string) ($raw['key'] ?? ''));
            $key = $key !== '' ? self::slug($key) : self::slug($label);
            $base = $key !== '' ? $key : 'service';
            $candidate = $base;
            $n = 2;

            while (isset($used[$candidate])) {
                $candidate = $base.'-'.$n;
                $n++;
            }

            $used[$candidate] = true;

            $clean[] = [
                'key' => $candidate,
                'label' => $label,
                'hint' => $hint,
                'unit' => $unit,
                'unitLabel' => $unitLabel,
                'rates' => $cleanRates,
            ];
        }

        $finishes = [];
        $rawFinishes = is_array($input['finishes'] ?? null) ? $input['finishes'] : [];

        foreach (self::FINISHES as $finish) {
            $found = null;

            foreach ($rawFinishes as $row) {
                if (is_array($row) && (string) ($row['key'] ?? '') === $finish) {
                    $found = $row;
                    break;
                }
            }

            $label = trim((string) ($found['label'] ?? ''));
            $hint = trim((string) ($found['hint'] ?? ''));

            if ($label === '' || mb_strlen($label) > 40) {
                throw new UnexpectedValueException("The {$finish} finish needs a name of at most 40 characters.");
            }

            if (mb_strlen($hint) > 160) {
                throw new UnexpectedValueException("The {$finish} finish description is over 160 characters.");
            }

            $finishes[] = ['key' => $finish, 'label' => $label, 'hint' => $hint];
        }

        $terms = [];
        $rawTerms = $input['terms'] ?? [];

        if (is_string($rawTerms)) {
            $rawTerms = preg_split('/\R/', $rawTerms) ?: [];
        }

        if (! is_array($rawTerms)) {
            throw new UnexpectedValueException('The quotation terms were sent in the wrong shape.');
        }

        foreach ($rawTerms as $term) {
            if (! is_scalar($term)) {
                continue;
            }

            $term = trim((string) $term);

            if ($term === '') {
                continue;
            }

            if (mb_strlen($term) > 300) {
                throw new UnexpectedValueException('A quotation term is over 300 characters.');
            }

            $terms[] = $term;
        }

        if (count($terms) > 12) {
            throw new UnexpectedValueException('Keep the quotation terms to 12 lines or fewer.');
        }

        $catalog = is_array($input['catalog'] ?? null) ? array_values($input['catalog']) : [];

        return ['services' => $clean, 'finishes' => $finishes, 'catalog' => $catalog, 'terms' => $terms];
    }

    /** Read the file the page actually loads and turn it into JSON that `json_decode()` accepts. */
    public static function decode(string $js): mixed
    {
        $json = self::toJson($js);

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new UnexpectedValueException('The estimator rate book is not readable: '.$e->getMessage());
        }
    }

    private static function toJson(string $js): string
    {
        $length = strlen($js);
        $out = '';
        $inString = false;
        $quote = '';
        $i = 0;

        // Start at the first `{` after the `window.WX_RATES =` assignment; the header comment is not data.
        $at = strpos($js, 'window.WX_RATES');
        $start = $at === false ? strpos($js, '{') : strpos($js, '{', $at);
        $i = $start === false ? 0 : $start;
        $depth = 0;

        for (; $i < $length; $i++) {
            $char = $js[$i];

            if ($inString) {
                $out .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $out .= $js[++$i];
                } elseif ($char === $quote) {
                    $inString = false;
                }

                continue;
            }

            // A comment, outside a string.
            if ($char === '/' && $i + 1 < $length && $js[$i + 1] === '*') {
                $end = strpos($js, '*/', $i + 2);
                $i = $end === false ? $length : $end + 1;

                continue;
            }

            if ($char === '/' && $i + 1 < $length && $js[$i + 1] === '/') {
                while ($i < $length && $js[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $inString = true;
                $quote = $char;
                $out .= '"';

                continue;
            }

            // A bare key: an identifier followed by a colon.
            if (preg_match('/[A-Za-z_$]/', $char) === 1) {
                $j = $i;
                while ($j < $length && preg_match('/[A-Za-z0-9_$]/', $js[$j]) === 1) {
                    $j++;
                }

                $word = substr($js, $i, $j - $i);
                $k = $j;
                while ($k < $length && ctype_space($js[$k])) {
                    $k++;
                }

                if ($k < $length && $js[$k] === ':') {
                    $out .= '"'.$word.'"';
                    $i = $j - 1;

                    continue;
                }

                // Not a key: a bare word (true/false/null or an undefined identifier).
                $out .= $word;
                $i = $j - 1;

                continue;
            }

            if ($char === '{' || $char === '[') {
                $depth++;
            }

            if ($char === '}' || $char === ']') {
                $depth--;
                $out .= $char;

                // The root object is closed: everything after it is the statement's `;`.
                if ($depth <= 0) {
                    break;
                }

                continue;
            }

            // A trailing comma before a closing bracket is legal JavaScript, not legal JSON.
            if ($char === ',') {
                $k = $i + 1;
                while ($k < $length && ctype_space($js[$k])) {
                    $k++;
                }

                if ($k < $length && ($js[$k] === '}' || $js[$k] === ']')) {
                    continue;
                }
            }

            $out .= $char;
        }

        return $out;
    }

    /**
     * Render the book as the file the estimator page loads.
     *
     * @param  array<string, mixed>  $book
     */
    private static function encode(array $book): string
    {
        $json = json_encode($book, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new UnexpectedValueException('The rate book could not be encoded.');
        }

        // Two-space indentation and unquoted keys, the way the file was written by hand.
        $json = (string) preg_replace_callback(
            '/^( +)/m',
            static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)),
            $json,
        );

        $json = (string) preg_replace('/^(\s*)"([A-Za-z_][A-Za-z0-9_]*)": /m', '$1$2: ', $json);

        return 'window.WX_RATES = '.$json.";\n";
    }

    private static function headerOf(string $old): string
    {
        if (preg_match('/^\s*\/\*.*?\*\//s', $old, $m) === 1) {
            return rtrim($m[0])."\n";
        }

        return self::HEADER;
    }

    private static function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }
}
