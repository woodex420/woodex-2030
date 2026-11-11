<?php

declare(strict_types=1);

namespace App\Domain\Bot\Fakes;

use App\Domain\Bot\BotReply;
use App\Domain\Bot\Contracts\BotProvider;

/**
 * Test double for a thinking provider (BOT-002): the test writes the reply, the fake remembers
 * exactly what the engine asked — the system prompt with its KNOWLEDGE block included, which is
 * how the retrieval tests prove what the model was allowed to know.
 */
final class FakeBotProvider implements BotProvider
{
    public string $reply = 'Of course — our team will confirm the details with you here.';

    public bool $fails = false;

    /** @var list<array{system: string, messages: list<array{role: string, content: string}>}> */
    public array $calls = [];

    public function isConfigured(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'anthropic'; // bound in place of the real one for tests
    }

    public function complete(string $system, array $messages): BotReply
    {
        $this->calls[] = ['system' => $system, 'messages' => $messages];

        return $this->fails
            ? BotReply::failed('the model did not answer')
            : new BotReply($this->reply, 120, 45);
    }
}
