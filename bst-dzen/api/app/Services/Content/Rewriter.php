<?php

namespace App\Services\Content;

use App\Models\OwnPublication;
use App\Models\RuleVersion;
use App\Models\SourceMaterial;
use App\Services\LLM\LlmClient;

/**
 * Рерайт материалов под формат Дзена.
 * Промпт собирается из базового стиля + активных правил (глобальный слой + слой канала).
 */
class Rewriter
{
    /** Приёмы заголовка для тегирования экспериментов */
    public static function headlinePattern(string $title): string
    {
        return match (true) {
            str_contains($title, '?') => 'question',
            str_contains($title, ':') => 'colon',
            (bool) preg_match('/\d/', $title) => 'number',
            str_contains($title, '«') => 'quote',
            default => 'plain',
        };
    }

    public function __construct(private readonly LlmClient $llm) {}

    public function rulesContext(int $ownChannelId): string
    {
        $rules = RuleVersion::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('own_channel_id')->orWhere('own_channel_id', $ownChannelId))
            ->orderByRaw('own_channel_id IS NULL') // проектный слой важнее глобального
            ->get();

        return $rules->isEmpty()
            ? ''
            : "\n\nДействующие правила рерайта (учитывай обязательно):\n"
                .$rules->map(fn (RuleVersion $r) => "## {$r->title}\n{$r->content}")->implode("\n\n");
    }

    /**
     * Рерайт одного материала. Создаёт OwnPublication (status=generated).
     */
    public function rewrite(SourceMaterial $material, int $ownChannelId): OwnPublication
    {
        $system = <<<'TXT'
        Ты редактор дзен-канала регионального телеканала. Переписываешь материал в формат Дзена.

        Требования к результату:
        - Объём: 400-700 слов (2-4 минуты чтения), абзацы по 2-4 предложения.
        - Заголовок: 60-75 символов, шаблон «эмоция/цитата/вопрос: конкретная суть», с цифрами или деталью.
        - Предложи РОВНО три варианта заголовка с разными приёмами: двоеточие с цитатой; вопрос; деталь с цифрой.
        - Первый абзац цепляет: конфликт, человеческая деталь или интрига — без «вводных» оборотов.
        - В конце — вопрос читателю для комментариев.
        - Стиль: простой, живой, без канцелярита; обращение к читателю на «вы».
        - Ничего не выдумывай: только факты исходника; при нехватке фактов — опиши контекст нейтрально.

        Верни JSON: {"titles": ["...","...","..."], "body": "html-текст с <p>-абзацами",
        "rubric": "одна из рубрик: " . implode('|', \App\Services\Analysis\RubricClassifier::rubricNames()) . "}
        TXT;

        $user = 'Материал для рерайта:'
            ."\nЗаголовок: {$material->title}"
            ."\nСсылка: {$material->url}"
            ."\nТекст:\n".mb_substr((string) $material->body, 0, 12000)
            .$this->rulesContext($ownChannelId);

        $result = $this->llm->chatJson('rewrite', [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], 0.7);

        $titles = array_values(array_slice((array) ($result['titles'] ?? []), 0, 3));
        $title = $titles[0] ?? $material->title;
        $body = (string) ($result['body'] ?? '');

        return OwnPublication::create([
            'own_channel_id' => $ownChannelId,
            'source_type' => $material->source === 'bst_site' ? 'site_rewrite' : 'external_rewrite',
            'source_url' => $material->url,
            'title' => $title,
            'headline_pattern' => self::headlinePattern($title),
            'body' => $body,
            'rubric' => $result['rubric'] ?? 'Прочее',
            'status' => 'generated',
            'experiment_tags' => ['titles' => $titles, 'material_id' => $material->id],
        ]);
    }
}
