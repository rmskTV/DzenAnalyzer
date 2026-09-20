<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\EventCluster;
use App\Models\Post;
use App\Services\Analysis\EventClusterer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    /** Дуэли инфоповодов: события скоупа own-канала + покрытие/скорость каналов */
    public function index(Request $request, EventClusterer $clusterer): JsonResponse
    {
        $own = $request->integer('own')
            ? Channel::own()->findOrFail($request->integer('own'))
            : Channel::own()->firstOrFail();

        $now = now()->getTimestamp();
        $maturedCutoff = $now - Post::MATURITY_HOURS * 3600;

        $clusters = EventCluster::with(['posts' => fn ($q) => $q->with('channel:id,title,dzen_key')])
            ->where('scope_channel_id', $own->id)
            ->orderByDesc('first_published_at')
            ->limit($request->integer('limit', 300))
            ->get();

        $duels = [];
        foreach ($clusters as $cluster) {
            // дуэли = события с участием own-канала; несозревшие посты (моложе 16 ч) «зреют»
            $members = $cluster->posts
                ->filter(fn ($p) => $p->published_at->getTimestamp() <= $maturedCutoff)
                ->sortBy('pivot.delay_min')->values();
            $ownPost = $members->firstWhere('channel_id', $own->id);
            if (! $ownPost) {
                continue;
            }
            $rivals = $members->reject(fn ($p) => $p->channel_id === $own->id);
            $best = $rivals->sortByDesc('views')->first();

            $duels[] = [
                'id' => $cluster->id,
                'first_published_at' => $cluster->first_published_at->toIso8601String(),
                'n_channels' => $cluster->n_channels,
                'own' => $this->member($ownPost),
                'best' => $best ? $this->member($best) : null,
                'ratio' => $best && $best->views > 0 ? round($ownPost->views / $best->views, 2) : null,
                'win' => $best ? $ownPost->views > $best->views : true,
            ];
        }

        $totalClusters = EventCluster::where('scope_channel_id', $own->id)->count();

        return response()->json([
            'own' => ['id' => $own->id, 'title' => $own->title],
            'total_events' => $totalClusters,
            'duels_count' => count($duels),
            'wins' => count(array_filter($duels, fn ($d) => $d['win'])),
            'coverage' => $clusterer->coverage($own),
            'duels' => $duels,
        ]);
    }

    private function member($post): array
    {
        return [
            'channel' => $post->channel->title,
            'title' => $post->title,
            'url' => $post->url,
            'published_at' => $post->published_at->toIso8601String(),
            'delay_min' => (int) $post->pivot->delay_min,
            'views' => $post->views,
        ];
    }
}
