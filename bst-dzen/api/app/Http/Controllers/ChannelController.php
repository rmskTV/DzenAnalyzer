<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChannelController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Channel::query()
                ->withCount('competitors')
                ->orderByDesc('is_own')
                ->orderBy('title')
                ->get(),
        );
    }

    public function posts(Channel $channel, Request $request): JsonResponse
    {
        $posts = $channel->posts()
            ->when($request->integer('days'), fn ($q, $days) => $q->where(
                'published_at',
                '>=',
                now()->subDays($days),
            ))
            ->when($request->input('rubric'), fn ($q, $rubric) => $q->where('rubric', $rubric))
            ->when($request->input('kind'), fn ($q, $kind) => $q->where('content_kind', $kind))
            ->when($request->input('format'), fn ($q, $format) => $q->where('content_format', $format))
            ->when(
                $request->input('sort') === 'views',
                fn ($q) => $q->orderByDesc('views'),
                fn ($q) => $q->orderByDesc('published_at'),
            )
            ->limit(min($request->integer('limit', 100), 500))
            ->get();

        return response()->json($posts);
    }

    /** Значения для фильтров топа постов: рубрики/форматы, встречающиеся у постов канала за окно */
    public function postFilters(Channel $channel, Request $request): JsonResponse
    {
        $days = $request->integer('days');

        $values = fn (string $column) => $channel->posts()
            ->whereNotNull($column)
            ->when($days > 0, fn ($q) => $q->where('published_at', '>=', now()->subDays($days)))
            ->select($column)->selectRaw('count(*) as n')
            ->groupBy($column)
            ->orderByDesc('n')
            ->get();

        return response()->json([
            'rubrics' => $values('rubric')->map(fn ($r) => ['name' => $r->rubric, 'n' => $r->n]),
            'formats' => $values('content_format')->map(fn ($r) => ['name' => $r->content_format, 'n' => $r->n]),
        ]);
    }

    /** Обновление роли/активности и конкурентного набора */
    public function update(Channel $channel, Request $request): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['sometimes', 'boolean'],
            'is_own' => ['sometimes', 'boolean'],
            'competitor_ids' => ['sometimes', 'array'],
            'competitor_ids.*' => ['integer', 'exists:channels,id'],
        ]);

        $channel->fill(collect($data)->only(['is_active', 'is_own'])->all())->save();

        if (array_key_exists('competitor_ids', $data) && $channel->is_own) {
            $channel->competitors()->sync($data['competitor_ids']);
        }

        return response()->json($channel->loadCount('competitors'));
    }

    public function stats(Channel $channel): JsonResponse
    {
        $stats = Post::where('channel_id', $channel->id)
            ->selectRaw('type, count(*) as n')
            ->selectRaw('sum(views) as views_sum')
            ->selectRaw('max(published_at) as last_post_at')
            ->groupBy('type')
            ->get();

        return response()->json([
            'channel' => $channel,
            'by_type' => $stats,
            'subscribers' => $channel->subscribers,
        ]);
    }
}
