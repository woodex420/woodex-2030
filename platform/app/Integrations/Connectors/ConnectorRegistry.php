<?php

declare(strict_types=1);

namespace App\Integrations\Connectors;

/** The connectors this build can really authorise, in the order the hub shows them. */
final class ConnectorRegistry
{
    /** @var array<string, Connector> */
    private array $connectors;

    public function __construct()
    {
        $connectors = [new GithubConnector, new GoogleWorkspaceConnector, new FacebookPageConnector, new CustomApiConnector];

        $this->connectors = [];

        foreach ($connectors as $connector) {
            $this->connectors[$connector->key()] = $connector;
        }
    }

    /** @return list<Connector> */
    public function all(): array
    {
        return array_values($this->connectors);
    }

    public function get(string $key): Connector
    {
        return $this->connectors[$key] ?? abort(404, 'There is no connector with that name.');
    }

    public function has(string $key): bool
    {
        return isset($this->connectors[$key]);
    }
}
