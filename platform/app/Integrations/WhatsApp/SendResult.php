<?php

declare(strict_types=1);

namespace App\Integrations\WhatsApp;

/**
 * What came back from Meta when we sent a message (WAP-004).
 *
 * A failed send is a normal outcome, not an exception: the message row is written with status `failed` and the
 * error code Meta gave us, so the inbox shows why a client did not receive something.
 */
final readonly class SendResult
{
    public function __construct(
        public bool $ok,
        public ?string $messageId = null,
        public ?string $error = null,
        public ?int $status = null,
    ) {}

    public static function sent(string $messageId): self
    {
        return new self(true, $messageId);
    }

    public static function failed(string $error, ?int $status = null): self
    {
        return new self(false, null, $error, $status);
    }
}
