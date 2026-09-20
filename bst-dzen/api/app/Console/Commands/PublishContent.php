<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\OwnPublication;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Публикация: перевозит одобренные материалы в очередь RSS-фида
 * согласно режимам по типам контента (settings: publish_modes)
 * и дневному лимиту (settings: content.max_per_day).
 */
class PublishContent extends Command
{
    protected $signature = 'dzen:publish';

    protected $description = 'Поставить одобренные публикации в RSS-очередь по режимам';

    private const DEFAULT_MODES = [
        'evergreen' => 'publish',
        'site_rewrite' => 'publish',
        'external_rewrite' => 'draft',
        'original' => 'draft',
    ];

    public function handle(): int
    {
        $modes = Setting::where('key', 'publish_modes')->whereNull('own_channel_id')->value('value')
            ?? self::DEFAULT_MODES;
        $maxPerDay = (int) data_get(
            Setting::where('key', 'content')->whereNull('own_channel_id')->value('value') ?? [],
            'max_per_day',
            8,
        );

        foreach (Channel::own()->where('is_active', true)->get() as $own) {
            $publishedToday = OwnPublication::where('own_channel_id', $own->id)
                ->whereIn('status', ['queued', 'published'])
                ->whereDate('scheduled_at', today())->count();
            $slots = max(0, $maxPerDay - $publishedToday);

            if ($slots === 0) {
                $this->line("{$own->dzen_key}: дневной лимит публикации ({$maxPerDay})");

                continue;
            }

            $approved = OwnPublication::where('own_channel_id', $own->id)
                ->whereIn('status', ['approved', 'edited', 'generated'])
                ->orderBy('created_at')
                ->limit($slots)
                ->get();

            $queued = 0;
            foreach ($approved as $publication) {
                $mode = $modes[$publication->source_type] ?? 'draft';

                if ($mode !== 'publish') {
                    continue; // draft — ждёт редактора в UI; hold — пропуск
                }

                if (in_array($publication->status, ['generated', 'edited'], true)) {
                    $this->warn("{$own->dzen_key}: #{$publication->id} ({$publication->source_type}) без одобрения редактора — режим publish требует approved, пропуск");

                    continue;
                }

                $publication->update(['status' => 'queued', 'scheduled_at' => now()]);
                $queued++;
            }

            $this->info("{$own->dzen_key}: в фид поставлено {$queued}");
        }

        return self::SUCCESS;
    }
}
