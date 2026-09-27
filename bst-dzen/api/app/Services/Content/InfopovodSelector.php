<?php

namespace App\Services\Content;

use App\Models\Setting;
use App\Models\SourceMaterial;
use Illuminate\Database\Eloquent\Collection;

/**
 * Отбор инфоповодов: новые материалы от парсеров, не использованные ранее.
 * Приоритет — свежесть (парсеры отдают сайт БСТ и внешние источники).
 */
class InfopovodSelector
{
    /** @return Collection<int, SourceMaterial> */
    public function selectNew(int $limit, array $excludeTypes = []): Collection
    {
        $query = SourceMaterial::where('status', 'new')
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderByDesc('published_at')
            ->limit($limit);

        if ($excludeTypes !== []) {
            $query->whereNotIn('source', $excludeTypes);
        }

        return $query->get();
    }

    public static function contentSettings(): array
    {
        return Setting::where('key', 'content')->whereNull('own_channel_id')->value('value') ?? [];
    }
}
