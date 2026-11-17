<?php

declare(strict_types=1);

namespace Database\Factories\Conversations;

use App\Domain\Conversations\Enums\Channel;
use App\Domain\Conversations\Enums\ConversationStatus;
use App\Domain\Conversations\Models\Conversation;
use App\Domain\Crm\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Conversation> */
final class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'channel' => Channel::Whatsapp->value,
            'contact_id' => Contact::factory(),
            'status' => ConversationStatus::Open->value,
            'external_number' => '+9232'.(string) $this->faker->numerify('#######'),
            'unread_count' => 0,
            'window_expires_at' => now()->addHours(20),
            'last_message_at' => now()->subMinutes(3),
            'labels' => null,
        ];
    }

    public function closed(): self
    {
        return $this->state(fn (): array => [
            'status' => ConversationStatus::Closed->value,
            'closed_at' => now()->subDay(),
        ]);
    }

    /** Outside Meta's 24-hour window: only a template may go out. */
    public function outOfWindow(): self
    {
        return $this->state(fn (): array => ['window_expires_at' => now()->subHour()]);
    }
}
