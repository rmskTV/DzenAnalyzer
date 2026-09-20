<?php

namespace App\Services\Analysis;

use App\Models\Channel;
use App\Models\EventCluster;
use App\Models\Post;
use Illuminate\Support\Collection;

/**
 * Кластеризация событий: одна новость у нескольких каналов.
 * Порт events.py: токенный Жаккар >= 0.3, окно ±48 ч, union-find.
 * Скоуп — конкурентный набор конкретного own-канала.
 */
class EventClusterer
{
    public const THRESHOLD = 0.30;

    public const WINDOW_SEC = 48 * 3600;

    private const STOP = 'в во и на из за по с со к у о об от до для что как это этот эта эти '
        . 'был была были будет быть он она они мы вы я не ни же бы ли а но или '
        . 'году года ещё еще уже там тогда который которая которые';

    /** @var array<int, string[]> */
    private array $tokensCache = [];

    /**
     * Перестроить кластеры для скоупа own-канала за окно.
     *
     * @return array{clusters: int, posts: int}
     */
    public function rebuild(Channel $own, int $days = 21): array
    {
        $channelIds = array_merge([$own->id], $own->competitors()->pluck('channels.id')->all());
        $since = now()->subDays($days);

        $posts = Post::whereIn('channel_id', $channelIds)
            ->where('published_at', '>=', $since)
            ->orderBy('published_at')
            ->get()
            ->values();

        $this->tokensCache = [];
        $n = $posts->count();
        $parent = range(0, $n - 1);

        $find = function (int $x) use (&$parent, &$find): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                /** @var Post $a */
                $a = $posts[$i];
                /** @var Post $b */
                $b = $posts[$j];
                if ($b->published_at->getTimestamp() - $a->published_at->getTimestamp() > self::WINDOW_SEC) {
                    break;
                }
                if ($a->channel_id === $b->channel_id) {
                    continue;
                }
                if ($this->jaccard($a, $b) >= self::THRESHOLD) {
                    $ra = $find($i);
                    $rb = $find($j);
                    if ($ra !== $rb) {
                        $parent[$rb] = $ra;
                    }
                }
            }
        }

        // атомарная замена кластеров скоупа
        EventCluster::where('scope_channel_id', $own->id)->delete();

        $groups = [];
        foreach ($parent as $idx => $root) {
            $groups[$root][] = $idx;
        }

        $clustersCreated = 0;
        foreach ($groups as $members) {
            $memberPosts = collect($members)->map(fn ($idx) => $posts[$idx]);
            if ($memberPosts->pluck('channel_id')->unique()->count() < 2) {
                continue;
            }
            $memberPosts = $memberPosts->sortBy('published_at')->values();
            $firstTs = $memberPosts[0]->published_at;

            $cluster = EventCluster::create([
                'scope_channel_id' => $own->id,
                'first_published_at' => $firstTs,
                'n_channels' => $memberPosts->pluck('channel_id')->unique()->count(),
                'title' => $memberPosts[0]->title,
            ]);

            $sync = [];
            foreach ($memberPosts as $post) {
                $sync[$post->id] = ['delay_min' => (int) round(($post->published_at->getTimestamp() - $firstTs->getTimestamp()) / 60)];
            }
            $cluster->posts()->attach($sync);
            $clustersCreated++;
        }

        return ['clusters' => $clustersCreated, 'posts' => $n];
    }

    /** Сводка покрытия/скорости по кластерам скоупа */
    public function coverage(Channel $own): array
    {
        $rows = \DB::table('event_members')
            ->join('event_clusters', 'event_clusters.id', '=', 'event_members.event_cluster_id')
            ->join('posts', 'posts.id', '=', 'event_members.post_id')
            ->join('channels', 'channels.id', '=', 'posts.channel_id')
            ->where('event_clusters.scope_channel_id', $own->id)
            ->select('channels.id as channel_id', 'channels.title', 'event_clusters.id as cluster_id', 'event_members.delay_min')
            ->get();

        $total = $rows->pluck('cluster_id')->unique()->count();

        return $rows->groupBy('channel_id')
            ->map(fn (Collection $group, $channelId) => [
                'channel_id' => (int) $channelId,
                'title' => $group->first()->title,
                'is_own' => (int) $channelId === $own->id,
                'n_events' => $group->pluck('cluster_id')->unique()->count(),
                'share' => $total > 0 ? round($group->pluck('cluster_id')->unique()->count() / $total * 100, 1) : 0,
                'median_delay_min' => $this->medianInt($group->pluck('delay_min')->all()),
                'first_count' => $group->where('delay_min', 0)->count(),
            ])
            ->values()
            ->sortByDesc('n_events')
            ->values()
            ->all();
    }

    private function jaccard(Post $a, Post $b): float
    {
        $ta = $this->tokens($a);
        $tb = $this->tokens($b);
        if ($ta === [] || $tb === []) {
            return 0;
        }
        $inter = count(array_intersect($ta, $tb));

        return $inter / (count($ta) + count($tb) - $inter);
    }

    /** @return string[] значимые токены заголовка+лида */
    private function tokens(Post $post): array
    {
        if (isset($this->tokensCache[$post->id])) {
            return $this->tokensCache[$post->id];
        }

        $text = mb_strtolower($post->title . ' ' . (string) $post->lead);
        $text = str_replace('ё', 'е', $text);
        preg_match_all('/[a-zа-я0-9]+/u', $text, $m);
        $stop = explode(' ', self::STOP);

        return $this->tokensCache[$post->id] = array_values(array_filter(
            $m[0],
            fn ($t) => mb_strlen($t) > 1 && ! in_array($t, $stop, true),
        ));
    }

    private function medianInt(array $values): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values);

        return (int) $values[(int) (count($values) / 2)];
    }
}
