<?php

declare(strict_types=1);

namespace App\Domain\Website\Contracts;

/**
 * Anything the shared library can hand a section (V2-3 F6/F7): the content entries (FAQs,
 * testimonials, logos) and the team members. One shape, two tables — sections and the editor's
 * pick-lists never care which table a row came from.
 */
interface LibraryEntry
{
    /** The entry's id — what a section stores when it picks the entry. */
    public function entryId(): int;

    /** What the entry is called in lists. */
    public function label(): string;

    /**
     * The entry as its section renders it.
     *
     * @return array<string, mixed>
     */
    public function toRow(): array;
}
