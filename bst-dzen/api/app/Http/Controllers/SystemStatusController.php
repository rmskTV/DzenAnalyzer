<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\EventCluster;
use App\Models\OwnPublication;
use App\Models\Post;
use App\Models\PostSnapshot;
use App\Models\Setting;
use App\Models\SourceMaterial;
use App\Services\LLM\LlmClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/** Состояние системы: сбор, ингест, конвейер, очередь, расписание. Без конкурентной аналитики. */
class SystemStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $llm = app(LlmClient::class);

        return response()->json([
            'channels' => [
                'total' => Channel::count(),
                'own' => Channel::own()->count(),
                'active' => Channel::where('is_active', true)->count(),
            ],
            'collection' => Channel::query()
                ->orderByDesc('is_own')
                ->orderBy('title')
                ->get(['id', 'title', 'dzen_key', 'dzen_mode', 'is_own', 'is_active', 'last_crawled_at'])
                ->map(fn ($ch) => [
                    'id' => $ch->id,
                    'title' => $ch->title,
                    'mode' => $ch->dzen_mode,
                    'is_own' => $ch->is_own,
                    'is_active' => $ch->is_active,
                    'last_crawled_at' => $ch->last_crawled_at?->toIso8601String(),
                    'posts' => $ch->posts()->count(),
                    'snapshots_today' => PostSnapshot::whereDate('snapshot_date', today())->count()
                        ? PostSnapshot::whereIn('post_id', $ch->posts()->select('id'))
                            ->whereDate('snapshot_date', today())->count()
                        : 0,
                ]),
            'ingest' => [
                'parsers_configured' => count(array_filter(
                    (array) (Setting::where('key', 'parsers')->value('value') ?? []),
                    fn ($p) => ($p['active'] ?? false) && ! empty($p['url']),
                )),
                'materials_total' => SourceMaterial::count(),
                'by_status' => SourceMaterial::query()
                    ->selectRaw('status, count(*) as n')
                    ->groupBy('status')
                    ->pluck('n', 'status'),
            ],
            'content' => [
                'publications_by_status' => OwnPublication::query()
                    ->selectRaw('status, count(*) as n')
                    ->groupBy('status')
                    ->pluck('n', 'status'),
                'generated_today' => OwnPublication::whereDate('created_at', today())->count(),
                'queued_in_feed' => OwnPublication::where('status', 'queued')->count(),
                'tracked_results' => OwnPublication::whereNotNull('result_views')->count(),
            ],
            'llm' => [
                'configured' => $llm->isConfigured(),
                'base_url' => (string) config('services.bothub.base_url'),
                'models' => collect(['classify', 'rewrite', 'evergreen', 'rules'])
                    ->mapWithKeys(fn ($op) => [$op => $llm->modelFor($op)]),
            ],
            'queue' => [
                'pending' => DB::table('jobs')->count(),
                'failed' => DB::table('failed_jobs')->count(),
            ],
            'db' => [
                'posts' => Post::count(),
                'snapshots' => PostSnapshot::count(),
                'event_clusters' => EventCluster::count(),
                'last_post_at' => optional(Post::max('published_at'))?->toIso8601String(),
            ],
            'schedule' => $this->schedule(),
            'taxonomy' => [
                'rubrics' => \DB::table('rubrics')
                    ->leftJoin('posts', 'posts.rubric', '=', 'rubrics.name')
                    ->select('rubrics.name', 'rubrics.created_by', \DB::raw('count(posts.id) as n'))
                    ->groupBy('rubrics.id', 'rubrics.name', 'rubrics.created_by')
                    ->orderByDesc('n')
                    ->get(),
                'formats' => \DB::table('formats')
                    ->leftJoin('posts', 'posts.content_format', '=', 'formats.name')
                    ->select('formats.name', 'formats.is_evergreen', \DB::raw('count(posts.id) as n'))
                    ->groupBy('formats.id', 'formats.name', 'formats.is_evergreen')
                    ->orderByDesc('n')
                    ->get(),
                'unclassified' => Post::whereNull('rubric')->orWhereNull('content_format')->count(),
            ],
            'time' => now()->toIso8601String(),
        ]);
    }

    /** Зеркало routes/console.php (UTC -> Иркутск UTC+8) */
    private function schedule(): array
    {
        return [
            ['command' => 'dzen:collect', 'utc' => '22:00', 'irk' => '06:00', 'desc' => 'сбор постов + снапшоты просмотров и подписчиков'],
            ['command' => 'dzen:ingest', 'utc' => '22:10', 'irk' => '06:10', 'desc' => 'забор материалов с внешних парсеров'],
            ['command' => 'dzen:classify', 'utc' => '22:15', 'irk' => '06:15', 'desc' => 'рубрики/форматы новым постам (LLM)'],
            ['command' => 'dzen:events', 'utc' => '22:18', 'irk' => '06:18', 'desc' => 'пересборка кластеров событий (дуэли, покрытие)'],
            ['command' => 'dzen:generate', 'utc' => '22:20', 'irk' => '06:20', 'desc' => 'черновики: evergreen-сетка + рерайты материалов'],
            ['command' => 'dzen:publish', 'utc' => '23:00', 'irk' => '07:00', 'desc' => 'постановка одобренных публикаций в RSS-очередь'],
            ['command' => 'dzen:track', 'utc' => '23:30', 'irk' => '07:30', 'desc' => 'связка публикаций с постами Дзена, фиксация результатов'],
            ['command' => 'dzen:rules (вс)', 'utc' => '01:00', 'irk' => '09:00', 'desc' => 'предложение новой версии правил рерайта (LLM)'],
        ];
    }
}
