<?php

namespace App\Services\Feedback;

use App\Models\Channel;
use App\Models\OwnPublication;
use App\Models\Post;
use Illuminate\Support\Collection;

/**
 * Обратная связь: связывает наши публикации с постами Дзена (по совпадению
 * заголовков) и фиксирует результат (просмотры созревшего поста) для
 * экспериментов над заголовками.
 */
class PublicationTracker
{
    /** Порог схожести заголовков (токенный Жаккар) */
    private const MATCH_THRESHOLD = 0.5;

    public function track(Channel $own): array
    {
        $stats = ['linked' => 0, 'updated' => 0];

        // 1) queued -> published: ищем появившийся в Дзене пост
        $pending = OwnPublication::where('own_channel_id', $own->id)
            ->where('status', 'queued')
            ->where('scheduled_at', '>=', now()->subDays(14))
            ->get();

        foreach ($pending as $publication) {
            $candidates = Post::where('channel_id', $own->id)
                ->where('published_at', '>=', $publication->scheduled_at?->copy()->subHours(2))
                ->get();

            $best = null;
            $bestScore = 0;
            $variants = array_merge([$publication->title], (array) data_get($publication->experiment_tags, 'titles', []));

            foreach ($candidates as $post) {
                foreach ($variants as $variant) {
                    $score = $this->similarity($variant, $post->title);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $best = $post;
                    }
                }
            }

            if ($best && $bestScore >= self::MATCH_THRESHOLD) {
                $publication->update([
                    'status' => 'published',
                    'published_post_id' => $best->id,
                    'published_at' => $best->published_at,
                ]);
                $stats['linked']++;
            }
        }

        // 2) фиксируем результат созревших публикаций (однократно)
        $published = OwnPublication::where('own_channel_id', $own->id)
            ->where('status', 'published')
            ->whereNotNull('published_post_id')
            ->get();

        foreach ($published as $publication) {
            if ($publication->result_views !== null) {
                continue; // результат уже зафиксирован
            }

            $post = $publication->publishedPost;
            if (! $post) {
                continue;
            }

            $ageHours = (now()->getTimestamp() - $post->published_at->getTimestamp()) / 3600;
            if ($ageHours < Post::MATURITY_HOURS) {
                continue; // пост ещё не созрел
            }

            $publication->update([
                'result_views' => $post->views,
                'last_checked_at' => now(),
            ]);
            $stats['updated']++;
        }

        return $stats;
    }

    /** Статистика приёмов заголовков по нашим опубликованным постам */
    public static function patternStats(int $ownChannelId): array
    {
        $rows = OwnPublication::where('own_channel_id', $ownChannelId)
            ->whereNotNull('result_views')
            ->get()
            ->groupBy('headline_pattern');

        $result = [];
        foreach ($rows as $pattern => $group) {
            /** @var Collection $group */
            $sorted = $group->pluck('result_views')->sort()->values();
            $result[] = [
                'pattern' => $pattern ?? 'plain',
                'n' => $group->count(),
                'median_views' => round((float) $sorted[(int) ($sorted->count() / 2)], 1),
                'max_views' => round((float) $sorted->max(), 1),
            ];
        }

        usort($result, fn ($a, $b) => $b['median_views'] <=> $a['median_views']);

        return $result;
    }

    private function similarity(string $a, string $b): float
    {
        $tokens = fn (string $s) => collect(preg_split('/[^0-9a-zа-я]+/ui', mb_strtolower($s)) ?? [])
            ->filter(fn ($t) => mb_strlen($t) > 2);

        $ta = $tokens($a);
        $tb = $tokens($b);
        if ($ta->isEmpty() || $tb->isEmpty()) {
            return 0;
        }

        $inter = $ta->intersect($tb)->count();

        return $inter / ($ta->count() + $tb->count() - $inter);
    }
}
