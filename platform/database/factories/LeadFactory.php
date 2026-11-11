<?php

namespace Database\Factories;

use App\Domain\Crm\Models\Contact;
use App\Domain\Crm\Models\Lead;
use App\Domain\Crm\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $created = now()->subHours(fake()->numberBetween(1, 24 * 20));

        return [
            'ref' => 'L-'.now()->format('Y').'-'.strtoupper(Str::random(6)),
            'contact_id' => Contact::factory(),
            'business_line' => fake()->randomElement(['interior', 'interior', 'project', 'furniture']),
            'service' => fake()->randomElement(['interior-design', 'renovation', 'fit-out', 'kitchen-design', 'architecture', 'furniture', '3d-visualization']),
            'property_type' => fake()->randomElement(['house', 'apartment', 'office', 'shop', 'restaurant']),
            'city' => 'Lahore',
            'area' => fake()->randomElement(['DHA Phase 6', 'Gulberg', 'Bahria Town', 'Johar Town', 'Model Town']),
            'area_sqft' => fake()->randomElement([900, 1350, 2250, 4500, 10000]),
            'lead_type' => 'new',
            'priority' => fake()->randomElement(['low', 'normal', 'normal', 'high']),
            'stage_id' => fn () => PipelineStage::query()->where('key', 'new')->value('id'),
            'stage_changed_at' => $created,
            'score' => fake()->numberBetween(20, 95),
            'expected_value_minor' => fake()->randomElement([800_000, 1_500_000, 3_500_000, 7_500_000]) * 100,
            'next_action_type' => fake()->randomElement(['call', 'whatsapp', 'visit']),
            'next_action_at' => now()->addHours(fake()->numberBetween(-48, 96)),
            'sla_due_at' => $created->copy()->addMinutes(15),
            'first_response_at' => fake()->boolean(80) ? $created->copy()->addMinutes(fake()->numberBetween(3, 40)) : null,
            'source' => fake()->randomElement(['website_form', 'whatsapp', 'facebook_lead_ad', 'referral', 'phone_call', 'estimator']),
            'message' => fake()->randomElement([
                'Looking for complete interior of a new 1 kanal house.',
                'Need kitchen and wardrobes for 10 marla house.',
                'Office fit-out for 4,500 sq ft in Gulberg.',
                'Renovation of two washrooms and lounge.',
            ]),
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }
}
