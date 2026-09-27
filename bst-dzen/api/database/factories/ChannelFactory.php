<?php

namespace Database\Factories;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dzen_key' => fake()->unique()->slug(2),
            'dzen_mode' => 'name',
            'title' => fake()->company(),
            'subscribers' => fake()->numberBetween(100, 50000),
            'timezone' => 'Asia/Krasnoyarsk',
            'is_active' => true,
            'is_own' => false,
        ];
    }

    /** Собственный канал анализируемого набора */
    public function own(): static
    {
        return $this->state(fn () => ['is_own' => true]);
    }
}
