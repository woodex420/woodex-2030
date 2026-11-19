<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use stdClass;

/**
 * The contract of /api/v1 (resources/api/v1.openapi.json), read for the tests that hold the application to it.
 *
 * differences() compares a value with a schema of the contract key by key and says what is different, in words.
 * It knows exactly the keywords named in KEYWORDS and reports any other as a difference: the contract cannot say
 * something this test would let pass unread.
 */
final class ApiContract
{
    /** What a schema may say. "description", "examples" and "title" are for people; the rest is checked. */
    public const array KEYWORDS = [
        '$ref', 'type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'const', 'pattern', 'format',
        'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'anyOf', 'description', 'examples', 'title',
    ];

    private const array FORMATS = [
        // The rule is narrower than the standard's: UTC, to the second, ending in Z.
        'date-time' => '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
        'uuid' => '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
    ];

    /** @param  array<string, mixed>  $document */
    private function __construct(private readonly array $document) {}

    public static function read(): self
    {
        return new self(json_decode((string) file_get_contents(resource_path('api/v1.openapi.json')), true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return $this->document;
    }

    /**
     * Every operation by the name of its route.
     *
     * @return array<string, array{method: string, path: string, operation: array<string, mixed>}>
     */
    public function operations(): array
    {
        $operations = [];

        foreach ($this->document['paths'] as $path => $methods) {
            foreach ($methods as $method => $operation) {
                $operations[$operation['operationId']] = ['method' => strtoupper($method), 'path' => $path, 'operation' => $operation];
            }
        }

        ksort($operations);

        return $operations;
    }

    /**
     * The names of an operation's parameters of one kind ("query", "header", "path"), those that are required.
     *
     * @return list<string>
     */
    public function parameters(string $operationId, string $in, bool $requiredOnly = false): array
    {
        $names = [];

        foreach ($this->operations()[$operationId]['operation']['parameters'] ?? [] as $parameter) {
            $parameter = $this->resolve($parameter);

            if ($parameter['in'] === $in && (! $requiredOnly || ($parameter['required'] ?? false))) {
                $names[] = $parameter['name'];
            }
        }

        return $names;
    }

    /**
     * The statuses an operation is documented to answer with.
     *
     * @return list<int>
     */
    public function statuses(string $operationId): array
    {
        return array_map(intval(...), array_keys($this->operations()[$operationId]['operation']['responses']));
    }

    /**
     * One documented answer: the schema of its JSON body (null when the body is not JSON) and its headers.
     *
     * @return array{schema: array<string, mixed>|null, types: list<string>, headers: list<string>}
     */
    public function answer(string $operationId, int $status): array
    {
        $response = $this->resolve($this->operations()[$operationId]['operation']['responses'][(string) $status]
            ?? throw new RuntimeException("The contract documents no {$status} for {$operationId}."));

        return [
            'schema' => $response['content']['application/json']['schema'] ?? null,
            'types' => array_keys($response['content'] ?? []),
            'headers' => array_keys($response['headers'] ?? []),
        ];
    }

    /** @return array<string, mixed> */
    public function schema(string $name): array
    {
        return $this->document['components']['schemas'][$name] ?? throw new RuntimeException("The contract has no schema {$name}.");
    }

    /**
     * Follow a "$ref" to what it points at.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function resolve(array $node): array
    {
        while (isset($node['$ref'])) {
            $target = $this->document;

            foreach (explode('/', ltrim((string) $node['$ref'], '#/')) as $step) {
                $target = $target[$step] ?? throw new RuntimeException("The contract points at {$node['$ref']}, which is not there.");
            }

            $node = $target;
        }

        return $node;
    }

    /**
     * Every schema that an answer can reach, by where it stands: for the rules about how the contract is written.
     *
     * @return array<string, array<string, mixed>>
     */
    public function schemasOfAnswers(): array
    {
        $found = [];
        $walk = function (array $schema, string $at) use (&$walk, &$found): void {
            if (isset($schema['$ref'])) {
                $at = (string) $schema['$ref'];
                $schema = $this->resolve($schema);
            }
            if (isset($found[$at])) {
                return;
            }
            $found[$at] = $schema;
            foreach ($schema['properties'] ?? [] as $name => $inner) {
                $walk($inner, $at.'.'.$name);
            }
            foreach (['items', 'additionalProperties'] as $keyword) {
                if (is_array($schema[$keyword] ?? null)) {
                    $walk($schema[$keyword], $at.'.'.$keyword);
                }
            }
            foreach ($schema['anyOf'] ?? [] as $index => $inner) {
                $walk($inner, $at.'.anyOf.'.$index);
            }
        };

        foreach ($this->operations() as $id => $entry) {
            foreach (array_keys($entry['operation']['responses']) as $status) {
                $schema = $this->answer($id, (int) $status)['schema'];
                if ($schema !== null) {
                    $walk($schema, "{$id} {$status}");
                }
            }
        }

        return $found;
    }

    /**
     * What is different between a value (decoded with objects as stdClass) and a schema. Empty when nothing is.
     *
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public function differences(mixed $value, array $schema, string $at = 'answer'): array
    {
        $schema = $this->resolve($schema);
        $problems = [];

        foreach (array_keys($schema) as $keyword) {
            if (! in_array($keyword, self::KEYWORDS, true)) {
                $problems[] = "{$at}: the contract says \"{$keyword}\", which this test cannot check";
            }
        }

        if (isset($schema['anyOf'])) {
            $none = [];
            foreach ($schema['anyOf'] as $option) {
                $found = $this->differences($value, $option, $at);
                if ($found === []) {
                    return $problems;
                }
                $none[] = implode(', ', $found);
            }

            return [...$problems, "{$at}: is none of the kinds the contract allows (".implode(' | ', $none).')'];
        }

        $kind = self::kind($value);

        if (isset($schema['type']) && ! in_array($kind, (array) $schema['type'], true)) {
            return [...$problems, "{$at}: is ".($kind === 'null' ? 'null' : "a {$kind}").', the contract says '.implode(' or ', (array) $schema['type'])];
        }

        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            $problems[] = "{$at}: is ".json_encode($value).', the contract says always '.json_encode($schema['const']);
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $problems[] = "{$at}: is ".json_encode($value).', the contract allows '.json_encode($schema['enum']);
        }

        if ($kind === 'string') {
            if (isset($schema['pattern']) && preg_match('~'.str_replace('~', '\~', $schema['pattern']).'~', $value) !== 1) {
                $problems[] = "{$at}: \"{$value}\" does not look like {$schema['pattern']}";
            }
            if (isset($schema['format'])) {
                $pattern = self::FORMATS[$schema['format']] ?? null;
                if ($pattern === null) {
                    $problems[] = "{$at}: the contract says format \"{$schema['format']}\", which this test cannot check";
                } elseif (preg_match($pattern, $value) !== 1) {
                    $problems[] = "{$at}: \"{$value}\" is not written as a {$schema['format']}";
                }
            }
            if (isset($schema['minLength']) && mb_strlen($value) < $schema['minLength']) {
                $problems[] = "{$at}: is shorter than {$schema['minLength']}";
            }
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                $problems[] = "{$at}: is longer than {$schema['maxLength']}";
            }
        }

        if ($kind === 'integer') {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $problems[] = "{$at}: is below {$schema['minimum']}";
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $problems[] = "{$at}: is above {$schema['maximum']}";
            }
        }

        if ($kind === 'array') {
            if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                $problems[] = "{$at}: has fewer than {$schema['minItems']} entries";
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $problems[] = "{$at}: has more than {$schema['maxItems']} entries";
            }
            if (is_array($schema['items'] ?? null)) {
                foreach ($value as $index => $entry) {
                    $problems = [...$problems, ...$this->differences($entry, $schema['items'], "{$at}[{$index}]")];
                }
            }
        }

        if ($kind === 'object') {
            $given = get_object_vars($value);
            $named = $schema['properties'] ?? [];

            foreach ($schema['required'] ?? [] as $name) {
                if (! array_key_exists($name, $given)) {
                    $problems[] = "{$at}: has no \"{$name}\"";
                }
            }

            foreach ($given as $name => $inner) {
                if (isset($named[$name])) {
                    $problems = [...$problems, ...$this->differences($inner, $named[$name], "{$at}.{$name}")];
                } elseif (($schema['additionalProperties'] ?? null) === false) {
                    $problems[] = "{$at}: has \"{$name}\", which the contract does not name";
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $problems = [...$problems, ...$this->differences($inner, $schema['additionalProperties'], "{$at}.{$name}")];
                }
            }
        }

        return $problems;
    }

    /** What a decoded JSON value is, in the contract's words. */
    private static function kind(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            $value instanceof stdClass => 'object',
            is_array($value) && array_is_list($value) => 'array',
            default => 'something that is not JSON',
        };
    }
}
