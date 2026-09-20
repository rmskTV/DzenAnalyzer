<?php

namespace App\Services\Content;

use App\Models\OwnPublication;
use App\Models\RuleVersion;
use App\Services\LLM\LlmClient;
use Illuminate\Support\Carbon;

/**
 * Генератор evergreen-сеток: «народные приметы» и «гороскоп» на дату.
 * Формат выбран по данным анализа: ×19-27 к медианным охватам канала.
 */
class EvergreenGenerator
{
    public function __construct(private readonly LlmClient $llm)
    {
    }

    public function generate(int $ownChannelId, ?Carbon $date = null): OwnPublication
    {
        $date ??= now();
        $date = $date->copy()->timezone('Asia/Irkutsk');
        $day = (int) $date->format('z');

        // чётный день года — приметы, нечётный — гороскоп
        $isPrimety = $day % 2 === 0;

        if ($isPrimety) {
            $title = 'Народные приметы на ' . $date->translatedFormat('j F') . ': чего нельзя делать сегодня';
            $system = <<<'TXT'
            Ты составляешь ежедневную рубрику «Народные приметы». Верни JSON:
            {"titles": ["...","...","..."], "body": "html с <p>-абзацами", "rubric": "Развлечения/лайфстайл"}
            Требования к тексту (400-600 слов):
            - какой сегодня день по народному календарю (праздник, память святого — если есть),
            - 5-7 примет этого дня (погода, быт, доход) с короткими пояснениями,
            - блок «чего сегодня нельзя делать» (3-4 запрета) и «что принесёт удачу»,
            - финал — вопрос читателю о его приметах.
            Заголовок: 3 варианта с приёмами «вопрос», «двоеточие+деталь», «запрет с интригой».
            TXT;
        } else {
            $title = 'Гороскоп на ' . $date->translatedFormat('j F') . ': кому сегодня повезёт';
            $system = <<<'TXT'
            Ты составляешь ежедневный гороскоп. Верни JSON:
            {"titles": ["...","...","..."], "body": "html с <p>-абзацами", "rubric": "Развлечения/лайфстайл"}
            Требования к тексту (400-600 слов):
            - общий прогноз дня (настроение дня, луна-фактор — без астрономических выдумок),
            - по каждому знаку 2-3 предложения: любовь, дело, осторожность; выделяй 2-3 «звезды дня»,
            - финал — лёгкий вопрос читателю (какой он знак).
            Заголовок: 3 варианта с приёмами «вопрос», «двоеточие+деталь», «интрига».
            TXT;
        }

        $rules = RuleVersion::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('own_channel_id')->orWhere('own_channel_id', $ownChannelId))
            ->orderByRaw('own_channel_id IS NULL')
            ->get()
            ->map(fn ($r) => "## {$r->title}\n{$r->content}")
            ->implode("\n\n");

        $result = $this->llm->chatJson('evergreen', [
            ['role' => 'system', 'content' => $system . ($rules ? "\n\nПравила рерайта:\n{$rules}" : '')],
            ['role' => 'user', 'content' => 'Дата: ' . $date->format('Y-m-d') . ' (Иркутск). Составь материал.'],
        ], 0.8);

        $titles = array_values(array_slice((array) ($result['titles'] ?? []), 0, 3));
        $finalTitle = $titles[0] ?? $title;

        return OwnPublication::create([
            'own_channel_id' => $ownChannelId,
            'source_type' => 'evergreen',
            'title' => $finalTitle,
            'headline_pattern' => Rewriter::headlinePattern($finalTitle),
            'body' => (string) ($result['body'] ?? ''),
            'rubric' => 'Развлечения/лайфстайл',
            'status' => 'generated',
            'experiment_tags' => ['titles' => $titles, 'kind' => $isPrimety ? 'primety' : 'horoscope'],
        ]);
    }
}
