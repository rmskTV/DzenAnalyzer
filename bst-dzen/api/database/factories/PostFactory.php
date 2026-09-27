<?php

namespace Database\Factories;

use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Post>
 */
class PostFactory extends Factory
{
    /** Счётчик для гарантированно уникальных url/dzen_post_id */
    private static int $sequence = 0;

    public function definition(): array
    {
        self::$sequence++;

        return [
            'channel_id' => ChannelFactory::new(),
            'dzen_post_id' => (string) self::$sequence,
            'type' => 'article',
            'title' => fake()->sentence(6),
            'lead' => fake()->sentence(12),
            'url' => 'https://dzen.ru/a/'.Str::random(12),
            // по умолчанию «созревший» пост — участвует в охватных метриках
            'published_at' => now()->subDay(),
            'views' => 1000,
            'comments' => 0,
            'size_sec' => 60,
        ];
    }

    /** Свежий пост, ещё не допущенный до охватных метрик */
    public function fresh(): static
    {
        return $this->state(fn () => ['published_at' => now()->subHour()]);
    }
}
