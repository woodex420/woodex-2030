<?php

namespace Database\Factories;

use App\Domain\Crm\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $first = fake()->randomElement(['Ali', 'Ayesha', 'Hamza', 'Fatima', 'Usman', 'Sana', 'Bilal', 'Hira', 'Omar', 'Zainab', 'Ahmed', 'Mariam']);
        $last = fake()->randomElement(['Khan', 'Raza', 'Malik', 'Butt', 'Chaudhry', 'Sheikh', 'Qureshi', 'Mirza', 'Iqbal', 'Siddiqui']);
        $phone = '+923'.fake()->numberBetween(0, 4).fake()->numerify('#########');

        return [
            'name' => "{$first} {$last}",
            'phone' => $phone,
            'whatsapp' => $phone,
            'email' => fake()->boolean(60) ? fake()->unique()->safeEmail() : null,
            'city' => 'Lahore',
            'area' => fake()->randomElement(['DHA Phase 5', 'DHA Phase 6', 'Gulberg', 'Bahria Town', 'Johar Town', 'Model Town', 'Lake City', 'Cantt']),
            'client_type' => 'individual',
            'preferred_language' => fake()->randomElement(['en', 'en', 'ur']),
            'source' => 'website_form',
            'last_activity_at' => now(),
        ];
    }
}
