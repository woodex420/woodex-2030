<?php

declare(strict_types=1);

namespace App\Domain\System\Actions;

use Spatie\LaravelSettings\Events\SavingSettings;

/**
 * Every change of a setting leaves a row in the list of changes (master list T-067).
 *
 * Settings decide who may give which discount, what a document says and who signs it, and where alerts go. They
 * were saved without a trace. This listens to every settings group at the moment it is saved and writes what
 * changed, from what to what, and who did it. A value that is a secret — a key, a token, a password — is never
 * written: the row says only that it changed.
 */
final class RecordSettingsChange
{
    private const int LONGEST = 500;

    public function handle(SavingSettings $event): void
    {
        // Nothing was read before this save (a new installation writing its first values): there is no "before".
        if ($event->originalValues === null) {
            return;
        }

        $before = $event->originalValues->all();
        $secret = array_map('strval', $event->settings::encrypted());
        $changed = [];

        foreach ($event->properties as $name => $value) {
            $old = $before[$name] ?? null;

            if ($this->same($old, $value)) {
                continue;
            }

            $changed[$name] = in_array($name, $secret, true) || preg_match('/token|secret|password|api_key|private_key/i', (string) $name) === 1
                ? ['secret' => true]
                : ['old' => $this->short($old), 'new' => $this->short($value)];
        }

        if ($changed === []) {
            return;
        }

        $group = $event->settings::group();

        activity('settings')
            ->withProperties(['group' => $group, 'changed' => $changed])
            ->log(ucfirst($group).' settings changed: '.implode(', ', array_keys($changed)).'.');
    }

    private function same(mixed $old, mixed $new): bool
    {
        return json_encode($this->plain($old)) === json_encode($this->plain($new));
    }

    private function plain(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            is_array($value) => array_map(fn (mixed $item): mixed => $this->plain($item), $value),
            is_object($value) => method_exists($value, 'toArray') ? $this->plain($value->toArray()) : (string) json_encode($value),
            default => $value,
        };
    }

    /** What is kept of a value: all of a short one, the beginning of a long text. */
    private function short(mixed $value): mixed
    {
        $value = $this->plain($value);

        if (is_string($value) && mb_strlen($value) > self::LONGEST) {
            return mb_substr($value, 0, self::LONGEST).'… ('.mb_strlen($value).' characters)';
        }

        if (is_array($value) && strlen((string) json_encode($value)) > 4 * self::LONGEST) {
            return mb_substr((string) json_encode($value), 0, 4 * self::LONGEST).'…';
        }

        return $value;
    }
}
