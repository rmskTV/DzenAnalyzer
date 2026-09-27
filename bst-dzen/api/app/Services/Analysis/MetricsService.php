<?php

namespace App\Services\Analysis;

use App\Models\Channel;
use App\Models\Post;
use Illuminate\Support\Collection;

/**
 * Серийная аналитика: порт показателей из analyze.py/analyze2.py.
 * Все расчёты в PHP поверх Eloquent-коллекций (объёмы ~тысячи строк).
 * Охватная метрика — сырые просмотры созревших постов (прожили в паблике >= Post::MATURITY_HOURS);
 * объёмные метрики (постов/день, сетка, динамика) считаются по всем постам.
 */
class MetricsService
{
    /** Ведра времени чтения статей, мин */
    public const LENGTH_BUCKETS = [
        ['<1', 0, 60],
        ['1-2', 60, 120],
        ['2-3', 120, 180],
        ['3-4', 180, 240],
        ['>4', 240, PHP_INT_MAX],
    ];

    /** Строка-заменитель для постов без классифицированной рубрики */
    public const UNCLASSIFIED_RUBRIC = 'Без рубрики';

    /** Порог «белого пятна»: максимум постов own-канала в окне */
    private const WHITE_SPOT_MAX_OWN_POSTS = 2;

    /** Порог «белого пятна»: минимум постов конкурентов в окне */
    private const WHITE_SPOT_MIN_COMPETITOR_POSTS = 10;

    /**
     * Конкурентная сводка: own-канал + только его конкурентный набор, за окно.
     */
    public function overview(Channel $own, int $days): array
    {
        $since = now()->subDays($days);
        $competitorIds = $own->competitors()->pluck('channels.id')->all();
        $scopeIds = array_merge([$own->id], $competitorIds);

        $channels = Channel::where('is_active', true)
            ->whereIn('id', $scopeIds)
            ->get()
            ->keyBy('id');

        $posts = Post::where('published_at', '>=', $since)
            ->whereIn('channel_id', $channels->keys()->all())
            ->get();

        $now = now()->getTimestamp();
        $matured = $this->matured($posts, $now);

        $rows = [];
        foreach ($channels as $channel) {
            $channelPosts = $posts->where('channel_id', $channel->id);
            if ($channelPosts->isEmpty()) {
                continue;
            }

            $channelMatured = $matured->where('channel_id', $channel->id);
            $viewsList = $channelMatured->pluck('views')->sort()->values();

            $viewsSum = (int) $channelMatured->sum('views');
            $commentsSum = (int) $channelMatured->sum('comments');

            $articles = $channelPosts->where('type', 'article');
            // evergreen — производное от формата (formats.is_evergreen);
            // посты без формата считаем новостными
            $evergreenFormats = RubricClassifier::evergreenFormats();
            $isEvergreen = fn (Post $p) => in_array($p->content_format, $evergreenFormats, true);
            $evergreen = $channelMatured->where('type', 'article')->filter($isEvergreen);
            $regular = $channelMatured->where('type', 'article')->reject($isEvergreen);

            $rows[] = [
                'id' => $channel->id,
                'title' => $channel->title,
                'dzen_key' => $channel->dzen_key,
                'is_own' => $channel->is_own,
                'in_scope' => $channel->is_own || in_array($channel->id, $competitorIds, true),
                'subscribers' => $channel->subscribers,
                'posts' => $channelPosts->count(),
                'posts_per_day' => round($channelPosts->count() / $days, 1),
                'articles' => $articles->count(),
                'shorts' => $channelPosts->where('type', 'short')->count(),
                'longs' => $channelPosts->where('type', 'video_long')->count(),
                'views_sum' => $viewsSum,
                'views_median' => round($this->median($viewsList), 1),
                'views_mean' => round($viewsList->avg() ?? 0, 1),
                'engagement' => $viewsSum > 0
                    ? round($commentsSum / $viewsSum * 1000, 2)
                    : 0,
                'evergreen_n' => $evergreen->count(),
                'regular_n' => $regular->count(),
                'evergreen_median_views' => $evergreen->isEmpty() ? null : round($this->median($evergreen->pluck('views')), 1),
                'regular_median_views' => $regular->isEmpty() ? null : round($this->median($regular->pluck('views')), 1),
            ];
        }

        usort($rows, fn ($a, $b) => ($b['is_own'] <=> $a['is_own']) ?: ($b['views_median'] <=> $a['views_median']));

        return [
            'window_days' => $days,
            'own' => ['id' => $own->id, 'title' => $own->title, 'dzen_key' => $own->dzen_key],
            'channels' => $rows,
            'length' => $this->lengthBuckets($posts, $now),
            'length_set' => $this->lengthBucketsFor($posts->where('type', 'article')->values(), $now),
            'formats' => $this->formatStats($posts, $now),
            'rubrics' => $this->rubricStats($posts, $now),
            'white_spots' => $this->whiteSpots($posts, $channels, $own, $now),
            'dynamics' => $this->dynamics($posts, $days),
            'publish_grid' => $this->publishGrids($posts, $channels),
            'benchmark' => $this->benchmark($rows),
        ];
    }

    /** Форматы -> n и медиана просмотров: набор в среднем ('set') + по каналам; все форматы реестра всегда */
    private function formatStats(Collection $posts, int $now): array
    {
        $formatNames = RubricClassifier::formatNames();

        $build = function (Collection $group) use ($formatNames, $now): array {
            $byFormat = $this->matured($group, $now)->groupBy(fn (Post $p) => $p->content_format ?? '');

            return collect($formatNames)
                ->map(function (string $name) use ($byFormat) {
                    $bucket = $byFormat->get($name, collect());

                    return [
                        'format' => $name,
                        'n' => $bucket->count(),
                        'views_median' => $bucket->isEmpty() ? null : round($this->median($bucket->pluck('views')), 1),
                    ];
                })
                ->values()
                ->all();
        };

        $result = ['set' => $build($posts)];
        foreach ($posts->groupBy('channel_id') as $channelId => $group) {
            $result[(string) $channelId] = $build($group);
        }

        return $result;
    }

    /**
     * Рубрики -> n/доля/медиана охвата: набор в среднем ('set') + по каналам.
     * n/доля — по всем постам (повестка включает шортсы); медиана охвата —
     * только созревшие статьи (шортсы дают несопоставимые просмотры).
     * Все рубрики реестра присутствуют всегда + строка «Без рубрики».
     *
     * @return array{names: string[], set: array<int, array{rubric: string, n: int, share: float, views_median: float|null}>, ...array<string, array<int, array{rubric: string, n: int, share: float, views_median: float|null}>>}
     */
    private function rubricStats(Collection $posts, int $now): array
    {
        $rubricNames = array_merge(RubricClassifier::rubricNames(), [self::UNCLASSIFIED_RUBRIC]);
        $nameOf = fn (Post $p) => $p->rubric ?? self::UNCLASSIFIED_RUBRIC;

        $build = function (Collection $group) use ($rubricNames, $nameOf, $now): array {
            $total = $group->count();
            $byRubric = $group->groupBy($nameOf);
            $maturedArticles = $this->matured($group, $now)
                ->where('type', 'article')
                ->groupBy($nameOf);

            return collect($rubricNames)
                ->map(function (string $name) use ($byRubric, $maturedArticles, $total) {
                    $bucket = $maturedArticles->get($name, collect());

                    return [
                        'rubric' => $name,
                        'n' => $byRubric->get($name, collect())->count(),
                        'share' => $total > 0 ? round($byRubric->get($name, collect())->count() / $total * 100, 1) : 0.0,
                        'views_median' => $bucket->isEmpty() ? null : round($this->median($bucket->pluck('views')), 1),
                    ];
                })
                ->values()
                ->all();
        };

        $result = ['names' => $rubricNames, 'set' => $build($posts)];
        foreach ($posts->groupBy('channel_id') as $channelId => $group) {
            $result[(string) $channelId] = $build($group);
        }

        return $result;
    }

    /**
     * Белые пятна: рубрики, где own почти не публикуется, у конкурентов
     * стабильно выходит и собирает больше нашей общей медианы.
     *
     * @param  Collection<int, Post>  $posts
     * @param  Collection<int, Channel>  $channels  ключи — id
     * @return array<int, array{rubric: string, own_n: int, competitors_n: int, competitors_median: float, best_channel: array{id: int, title: string, n: int, views_median: float}|null}>
     */
    private function whiteSpots(Collection $posts, Collection $channels, Channel $own, int $now): array
    {
        $nameOf = fn (Post $p) => $p->rubric ?? self::UNCLASSIFIED_RUBRIC;

        $ownPosts = $posts->where('channel_id', $own->id);
        $competitorPosts = $posts->where('channel_id', '!=', $own->id);
        if ($competitorPosts->isEmpty()) {
            return [];
        }

        $ownByRubric = $ownPosts->groupBy($nameOf);
        $ownOverallMedian = $this->median(
            $this->matured($ownPosts->where('type', 'article'), $now)->pluck('views'),
        );

        $spots = [];
        foreach ($competitorPosts->groupBy($nameOf) as $rubric => $group) {
            $ownN = $ownByRubric->get($rubric, collect())->count();
            if ($rubric === self::UNCLASSIFIED_RUBRIC
                || $ownN > self::WHITE_SPOT_MAX_OWN_POSTS
                || $group->count() < self::WHITE_SPOT_MIN_COMPETITOR_POSTS) {
                continue;
            }

            $articles = $this->matured($group->where('type', 'article'), $now);
            if ($articles->isEmpty()) {
                continue;
            }

            $median = $this->median($articles->pluck('views'));
            if ($median <= $ownOverallMedian) {
                continue;
            }

            $best = null;
            foreach ($articles->groupBy('channel_id') as $channelId => $byChannel) {
                $channelMedian = $this->median($byChannel->pluck('views'));
                if ($best === null || $channelMedian > $best['views_median']) {
                    $best = [
                        'id' => $channelId,
                        'title' => $channels[$channelId]->title ?? (string) $channelId,
                        'n' => $byChannel->count(),
                        'views_median' => $channelMedian,
                    ];
                }
            }

            $spots[] = [
                'rubric' => $rubric,
                'own_n' => $ownN,
                'competitors_n' => $group->count(),
                'competitors_median' => round($median, 1),
                'best_channel' => $best,
            ];
        }

        usort($spots, fn (array $a, array $b) => $b['competitors_median'] <=> $a['competitors_median']);

        return array_slice($spots, 0, 10);
    }

    /** Сетка публикаций 7x24 (Пн..Вс x часы) в местном времени каждого канала */
    private function publishGrids(Collection $posts, Collection $channels): array
    {
        $grids = [];
        foreach ($channels as $channel) {
            $grids[$channel->id] = [
                'channel_id' => $channel->id,
                'title' => $channel->title,
                'is_own' => $channel->is_own,
                'timezone' => $channel->timezone,
                'grid' => array_fill(0, 7, array_fill(0, 24, 0)),
            ];
        }

        foreach ($posts as $post) {
            if (! isset($grids[$post->channel_id])) {
                continue;
            }
            $local = $post->published_at->copy()->setTimezone($grids[$post->channel_id]['timezone']);
            $weekday = ((int) $local->format('w') + 6) % 7; // Пн=0
            $grids[$post->channel_id]['grid'][$weekday][(int) $local->format('G')]++;
        }

        return array_values($grids);
    }

    /**
     * Вёдра времени чтения -> медиана просмотров, по каналам.
     * Ничего не отбрасываем: все вёдра присутствуют всегда (n=0 -> медиана null).
     */
    private function lengthBuckets(Collection $posts, int $now): array
    {
        $result = [];
        foreach ($posts->where('type', 'article')->groupBy('channel_id') as $channelId => $articles) {
            $result[(string) $channelId] = $this->lengthBucketsFor($articles->values(), $now);
        }

        return $result;
    }

    /** @param  Collection<int, Post>  $articles только статьи одного канала или набора */
    private function lengthBucketsFor(Collection $articles, int $now): array
    {
        $articles = $this->matured($articles, $now);
        $buckets = [];
        foreach (self::LENGTH_BUCKETS as [$label, $lo, $hi]) {
            $bucket = $articles
                ->filter(fn (Post $p) => $p->size_sec >= $lo && $p->size_sec < $hi)
                ->values();
            $buckets[] = [
                'bucket' => $label,
                'n' => $bucket->count(),
                'views_median' => $bucket->isEmpty() ? null : round($this->median($bucket->pluck('views')), 1),
            ];
        }

        return $buckets;
    }

    /** Постов в день по каналам (для графика динамики) */
    private function dynamics(Collection $posts, int $days): array
    {
        $byDate = [];
        foreach ($posts as $post) {
            $date = $post->published_at->toDateString();
            $byDate[$date][$post->channel_id] = ($byDate[$date][$post->channel_id] ?? 0) + 1;
        }
        ksort($byDate);

        return array_map(
            fn ($date, $counts) => ['date' => $date, 'counts' => $counts],
            array_keys($byDate),
            array_values($byDate),
        );
    }

    /** Own против лучшего в скоупе по ключевым метрикам */
    private function benchmark(array $rows): array
    {
        $scope = array_values(array_filter($rows, fn ($r) => $r['in_scope']));
        $ownRow = array_values(array_filter($scope, fn ($r) => $r['is_own']))[0] ?? null;
        if (! $ownRow) {
            return [];
        }

        $metrics = [
            ['key' => 'views_median', 'title' => 'Медиана просмотров (посты '.Post::MATURITY_HOURS.'ч+)'],
            ['key' => 'engagement', 'title' => 'Комментариев на 1000 просмотров'],
            ['key' => 'posts_per_day', 'title' => 'Публикаций в день'],
            ['key' => 'views_sum', 'title' => 'Суммарные просмотры за окно'],
        ];

        $result = [];
        foreach ($metrics as $metric) {
            $best = null;
            foreach ($scope as $r) {
                if ($r['is_own']) {
                    continue;
                }
                if ($best === null || $r[$metric['key']] > $best[$metric['key']]) {
                    $best = $r;
                }
            }
            $result[] = [
                'metric' => $metric['title'],
                'own' => $ownRow[$metric['key']],
                'best' => $best[$metric['key']] ?? null,
                'best_channel' => $best['title'] ?? null,
                'ratio' => ($best[$metric['key']] ?? 0) > 0
                    ? round($ownRow[$metric['key']] / $best[$metric['key']], 3)
                    : null,
            ];
        }

        return $result;
    }

    /** Посты, прожившие в паблике достаточно для участия в охватных метриках */
    private function matured(Collection $posts, int $now): Collection
    {
        $cutoff = $now - Post::MATURITY_HOURS * 3600;

        return $posts->filter(fn (Post $p) => $p->published_at->getTimestamp() <= $cutoff);
    }

    private function median(Collection $values): float
    {
        $sorted = $values->sort()->values();
        $n = $sorted->count();
        if ($n === 0) {
            return 0.0;
        }

        return (float) $sorted[(int) ($n / 2)];
    }
}
