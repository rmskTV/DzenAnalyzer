<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\SourceMaterial;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Забор материалов с внешних парсеров (сайт БСТ, другие сайты, ТГ).
 *
 * Эндпоинты настраиваются в settings (ключ 'parsers', редактируется из UI):
 * value = [{ "name": "bst_site", "url": "http://...", "active": true }, ...]
 *
 * Ожидаемый формат ответа: JSON-массив
 * [{ "external_id": "...", "url": "...", "title": "...",
 *    "text": "...", "published_at": "2026-09-19 10:00:00" }, ...]
 */
class IngestMaterials extends Command
{
    protected $signature = 'dzen:ingest';

    protected $description = 'Забрать материалы с внешних парсеров (HTTP)';

    public function handle(): int
    {
        $parsers = Setting::where('key', 'parsers')->whereNull('own_channel_id')->value('value') ?? [];

        $active = array_values(array_filter($parsers, fn ($p) => ($p['active'] ?? false) && ! empty($p['url'])));
        if ($active === []) {
            $this->info('Парсеры не настроены (settings: parsers) — пропуск.');

            return self::SUCCESS;
        }

        foreach ($active as $parser) {
            $name = $parser['name'] ?? 'unknown';
            try {
                $response = Http::timeout(60)->retry(2, 3000)->get($parser['url']);
                $items = $response->throw()->json();
            } catch (\Throwable $e) {
                $this->error("[{$name}] ошибка запроса: {$e->getMessage()}");

                continue;
            }

            if (! is_array($items)) {
                $this->warn("[{$name}] неожиданный формат ответа");

                continue;
            }

            $created = 0;
            foreach ($items as $item) {
                if (empty($item['title'])) {
                    continue;
                }
                $created += SourceMaterial::firstOrCreate(
                    [
                        'source' => $name,
                        'external_id' => (string) ($item['external_id'] ?? $item['url'] ?? md5($item['title'])),
                    ],
                    [
                        'url' => $item['url'] ?? null,
                        'title' => $item['title'],
                        'body' => $item['text'] ?? null,
                        'published_at' => isset($item['published_at']) ? Carbon::parse($item['published_at']) : now(),
                        'status' => 'new',
                    ],
                )->wasRecentlyCreated ? 1 : 0;
            }

            $this->info("[{$name}] получено: ".count($items).", новых: {$created}");
        }

        return self::SUCCESS;
    }
}
