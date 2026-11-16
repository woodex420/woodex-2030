<?php

declare(strict_types=1);

namespace App\Integrations\Connectors;

use App\Models\User;
use RuntimeException;

/**
 * One provider the company can really authorise.
 *
 * A connector answers only two questions the hub cares about: how the company signs in, and what the provider
 * says about that sign-in *now*. `probe()` always makes the real request — nothing is inferred from a saved flag
 * — so "Connected" on the screen is a measurement, not a memory.
 */
abstract class Connector
{
    /** Machine key, stored in `integration_connections.provider`. */
    abstract public function key(): string;

    abstract public function name(): string;

    /** `token` (a key the owner pastes) or `oauth` (a browser round trip). */
    abstract public function mode(): string;

    abstract public function icon(): string;

    /** One line under the name on the card. */
    abstract public function detail(): string;

    /** What the company needs before this connector can work, or null when it is ready. */
    abstract public function missingRequirement(): ?string;

    /** Where the owner gets this credential, in their words. Shown in the hub's Details. */
    public function help(): ?string
    {
        return null;
    }

    /**
     * The input fields for `token` connectors.
     *
     * @return list<array{name: string, label: string, type: string, placeholder: string, required: bool}>
     */
    public function fields(): array
    {
        return [];
    }

    /**
     * Authorise with a pasted credential and prove it before anything is stored.
     *
     * @param  array<string, string>  $input
     * @return array{credentials: array<string, mixed>, account: string, scopes: list<string>, meta: array<string, mixed>}
     */
    public function connect(array $input, User $user): array
    {
        throw new RuntimeException('This connector does not accept a pasted credential.');
    }

    public function authorizeUrl(string $state): string
    {
        throw new RuntimeException('This connector does not use a browser authorisation.');
    }

    /**
     * Finish a browser authorisation. The code is exchanged for tokens and the token is proved before it is stored.
     *
     * @return array{credentials: array<string, mixed>, account: string, scopes: list<string>, meta: array<string, mixed>}
     */
    public function complete(string $code, User $user): array
    {
        throw new RuntimeException('This connector does not use a browser authorisation.');
    }

    /**
     * Ask the provider, now.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, account: string|null, detail: string, scopes: list<string>|null, meta: array<string, mixed>, error: string|null}
     */
    abstract public function probe(array $credentials): array;

    /**
     * Best-effort revocation at the provider. A failure must not keep a local row alive.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function disconnect(array $credentials): void
    {
        // Most token providers have no revocation endpoint; deleting the row is the disconnect.
    }
}
