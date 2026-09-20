<?php

namespace App\Console\Commands;

use App\Jobs\GeneratePublication;
use App\Models\Channel;
use App\Models\OwnPublication;
use App\Models\SourceMaterial;
use App\Services\Content\InfopovodSelector;
use App\Services\LLM\LlmClient;
use Illuminate\Console\Command;

/**
 * Генерация контента на день: 1 evergreen + рерайты новых материалов,
 * в пределах дневной нормы (settings: content.daily_limit, по умолчанию 4).
 */
class GenerateContent extends Command
{
    protected $signature = 'dzen:generate {--limit= : дневная норма публикаций}';

    protected $description = 'Сгенерировать публикации (evergreen + рерайты) для own-каналов';

    public function handle(): int
    {
        if (! app(LlmClient::class)->isConfigured()) {
            $this->warn('LLM не настроен (LLM_BASE_URL/LLM_API_KEY в .env) — генерация пропущена.');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit')
            ?? data_get(InfopovodSelector::contentSettings(), 'daily_limit', 4));

        foreach (Channel::own()->where('is_active', true)->get() as $own) {
            $generatedToday = OwnPublication::where('own_channel_id', $own->id)
                ->whereDate('created_at', today())->count();
            $slots = max(0, $limit - $generatedToday);

            if ($slots === 0) {
                $this->line("{$own->dzen_key}: дневная норма достигнута ({$generatedToday})");

                continue;
            }

            // 1) evergreen-сетка (если ещё не было сегодня)
            $evergreenToday = OwnPublication::where('own_channel_id', $own->id)
                ->where('source_type', 'evergreen')
                ->whereDate('created_at', today())->exists();

            if (! $evergreenToday) {
                GeneratePublication::dispatch($own->id, 'evergreen');
                $slots--;
                $this->line("{$own->dzen_key}: job evergreen отправлен");
            }

            // 2) рерайты новых материалов
            if ($slots > 0) {
                $usedIds = OwnPublication::where('own_channel_id', $own->id)
                    ->whereNotNull('experiment_tags')
                    ->get()
                    ->map(fn ($p) => data_get($p->experiment_tags, 'material_id'))
                    ->filter()
                    ->all();

                $materials = SourceMaterial::where('status', 'new')
                    ->whereNotNull('body')->where('body', '!=', '')
                    ->when($usedIds, fn ($q) => $q->whereNotIn('id', $usedIds))
                    ->orderByDesc('published_at')
                    ->limit($slots)->get();

                foreach ($materials as $material) {
                    GeneratePublication::dispatch($own->id, 'material', $material->id);
                    $slots--;
                    $this->line("{$own->dzen_key}: job рерайта #{$material->id} отправлен ({$material->source})");
                }
            }

            if ($slots === (int) ($limit - $generatedToday) - ($evergreenToday ? 0 : 1)) {
                $this->line("{$own->dzen_key}: нет новых материалов от парсеров");
            }
        }

        return self::SUCCESS;
    }
}
