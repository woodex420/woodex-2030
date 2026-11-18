<?php

declare(strict_types=1);

namespace Database\Factories\Conversations;

use App\Domain\Conversations\Enums\MessageDirection;
use App\Domain\Conversations\Enums\MessageStatus;
use App\Domain\Conversations\Enums\MessageType;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Conversations\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Message> */
final class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'direction' => MessageDirection::In->value,
            'type' => MessageType::Text->value,
            'body' => $this->faker->sentence(8),
            'status' => MessageStatus::Delivered->value,
        ];
    }

    public function inbound(): self
    {
        return $this->state(fn (): array => ['direction' => MessageDirection::In->value, 'status' => MessageStatus::Delivered->value]);
    }

    public function outbound(): self
    {
        return $this->state(fn (): array => ['direction' => MessageDirection::Out->value, 'status' => MessageStatus::Sent->value]);
    }

    public function fromClient(string $body): self
    {
        return $this->state(fn (): array => ['direction' => MessageDirection::In->value, 'body' => $body]);
    }

    public function note(string $body): self
    {
        return $this->state(fn (): array => [
            'direction' => MessageDirection::Out->value,
            'type' => MessageType::Note->value,
            'body' => $body,
            'status' => MessageStatus::Sent->value,
        ]);
    }
}
