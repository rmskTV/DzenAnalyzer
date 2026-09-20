<?php

namespace App\Services\Feedback;

use App\Models\OwnPublication;
use App\Models\RuleVersion;
use App\Services\LLM\LlmClient;
use Illuminate\Support\Collection;

/**
 * Движок правил рерайта: еженедельная выжимка статистики ->
 * LLM предлагает новую версию правил (неактивна до одобрения в UI).
 */
class RulesEngine
{
    public function __construct(private readonly LlmClient $llm)
    {
    }

    public function proposeGlobal(): ?RuleVersion
    {
        $published = OwnPublication::whereNotNull('result_vpd')->orderByDesc('result_vpd')->get();

        if ($published->count() < 5) {
            return null; // недостаточно данных для обучения
        }

        $patterns = PublicationTracker::patternStats(
            $published->first()->own_channel_id,
        );

        $context = [
            'win_rate_patternов' => $patterns,
            'топ-5_наших_постов' => $published->take(5)->map(fn ($p) => [
                'title' => $p->title, 'vpd' => $p->result_vpd, 'pattern' => $p->headline_pattern,
            ])->values(),
            'худшие-5' => $published->reverse()->take(5)->map(fn ($p) => [
                'title' => $p->title, 'vpd' => $p->result_vpd, 'pattern' => $p->headline_pattern,
            ])->values(),
        ];

        $current = RuleVersion::where('layer', 'global')->where('is_active', true)->first();

        $result = $this->llm->chatJson('rules', [
            ['role' => 'system', 'content' => <<<'TXT'
            Ты оптимизируешь правила рерайта постов для дзен-канала. На вход — статистика
            приёмов заголовков (median_vpd — просмотры за день жизни поста) и лучшие/худшие посты,
            текущие правила. Предложи обновлённый набор правил рерайта (3-8 конкретных пунктов,
            приоритизированных). Правила должны быть проверяемыми и адресовать найденные паттерны.
            Верни JSON: {"title": "версия от <дата>", "content": "markdown-правила",
            "summary": "что изменил и почему (2-4 предложения)"}
            TXT],
            ['role' => 'user', 'content' => json_encode([
                'статистика' => $context,
                'текущие_правила' => $current?->content ?? '(нет — первый набор)',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
        ], 0.4);

        return RuleVersion::create([
            'own_channel_id' => null,
            'layer' => 'global',
            'title' => (string) ($result['title'] ?? 'Версия от ' . now()->toDateString()),
            'content' => (string) ($result['content'] ?? ''),
            'summary' => (string) ($result['summary'] ?? ''),
            'is_active' => false,
        ]);
    }
}
