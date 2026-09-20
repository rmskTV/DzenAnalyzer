<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\EventCluster;
use App\Services\Analysis\EventClusterer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class EventController extends Controller
{
    /** Дуэли инфоповодов: события скоупа own-канала + покрытие/скорость каналов */
    public function index(Request $request, EventClusterer $clusterer): JsonResponse
    {
        $own = $request->integer('own')
            ? Channel::own()->findOrFail($request->integer('own'))
            : Channel::own()->firstOrFail();

        $now = now()->getTimestamp();
        $vpd = fn ($post) => round($post->views / max(0.5, ($now - $post->published_at->getTimestamp()) / 86400), 1);

        $clusters = EventCluster::with(['posts' => fn ($q) => $q->with('channel:id,title,dzen_key')])
            ->where('scope_channel_id', $own->id)
            ->orderByDesc('first_published_at')
            ->limit($request->integer('limit', 300))
            ->get();

        $duels = [];
        foreach ($clusters as $cluster) {
            $members = $cluster->posts->sortBy('pivot.delay_min')->values();
            $ownPost = $members->firstWhere('channel_id', $own->id);
            if (! $ownPost) {
                continue; // дуэли = события с участием own-канала
            }
            $rivals = $members->reject(fn ($p) => $p->channel_id === $own->id);
            $best = $rivals->sortByDesc(fn ($p) => $vpd($p))->first();

            $duels[] = [
                'id' => $cluster->id,
                'first_published_at' => $cluster->first_published_at->toIso8601String(),
                'n_channels' => $cluster->n_channels,
                'own' => $this->member($ownPost, $vpd($ownPost)),
                'best' => $best ? $this->member($best, $vpd($best)) : null,
                'ratio' => $best && $vpd($best) > 0 ? round($vpd($ownPost) / $vpd($best), 2) : null,
                'win' => $best ? $vpd($ownPost) > $vpd($best) : true,
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

    private function member($post, float $vpd): array
    {
        return [
            'channel' => $post->channel->title,
            'title' => $post->title,
            'url' => $post->url,
            'published_at' => $post->published_at->toIso8601String(),
            'delay_min' => (int) $post->pivot->delay_min,
            'views' => $post->views,
            'vpd' => $vpd,
        ];
    }
}
