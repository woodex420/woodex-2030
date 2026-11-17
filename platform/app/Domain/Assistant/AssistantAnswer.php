<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

/**
 * One assistant answer (slice M): a plain headline built from numbers, the records it read as citations,
 * and — for anything outside the whitelist or the asker's permissions — an honest refusal. Nothing in
 * here is generated prose: every figure came out of the query named in `$source`.
 *
 * @phpstan-type Citation array{label: string, detail: string, url: string}
 */
final readonly class AssistantAnswer
{
    /**
     * @param  list<Citation>  $rows
     */
    public function __construct(
        public string $headline,
        public array $rows = [],
        public string $source = '',
        public bool $refused = false,
        public ?int $totalMinor = null,
    ) {}

    public static function refusal(string $headline): self
    {
        return new self($headline, refused: true);
    }

    /**
     * @return array{headline: string, rows: list<Citation>, source: string, refused: bool, total_minor: ?int}
     */
    public function toArray(): array
    {
        return [
            'headline' => $this->headline,
            'rows' => $this->rows,
            'source' => $this->source,
            'refused' => $this->refused,
            'total_minor' => $this->totalMinor,
        ];
    }
}
