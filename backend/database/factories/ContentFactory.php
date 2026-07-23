<?php

namespace Database\Factories;

use App\Models\Content;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Content>
 */
class ContentFactory extends Factory
{
    protected $model = Content::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'offer_id' => null,
            'title' => fake()->sentence(4),
            'body' => fake()->paragraphs(3, true),
            'media_path' => null,
            'media_filename' => null,
            'media_mime' => null,
            'media_size' => null,
            'status' => Content::STATUS_DRAFT,
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => ['status' => Content::STATUS_PUBLISHED]);
    }
}
