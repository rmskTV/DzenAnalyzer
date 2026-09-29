<?php

namespace App\Console\Commands;

use App\Jobs\CrawlChannel;
use App\Models\Channel;
use App\Services\Dzen\DzenCrawler;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Ежедневный сбор: все активные каналы -> посты + снапшоты просмотров.
 * По умолчанию окно 21 день — совпадает с окном отчётов, просмотры постов
 * обновляются всю их жизнь в отчёте.
 *
 * Примеры:
 *   php artisan dzen:collect --sync
 *   php artisan dzen:collect --sync --days=7
 *   php artisan dzen:collect --sync --channel=14          # own-канал + его конкуренты
 *   php artisan dzen:collect --sync --channel=niann       # конкретный канал по ключу
 */
class CollectDzen extends Command
{
    protected $signature = 'dzen:collect
        {--days=21 : глубина окна сбора}
        {--channel= : только этот канал (ID или dzen_key); own-канал — вместе с его конкурентами}
        {--sync : выполнять без очереди (последовательно)}';

    protected $description = 'Собрать публикации активных каналов Дзена + снапшоты';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $ref = trim((string) $this->option('channel'));

        if ($ref === '') {
            $channels = Channel::where('is_active', true)->orderByDesc('is_own')->get();
            $filter = '';
        } else {
            $channels = $this->resolveChannels($ref);

            if ($channels === null) {
                return self::FAILURE;
            }

            $filter = ", фильтр: {$ref}";
        }

        if ($channels->isEmpty()) {
            $this->warn('Нет активных каналов.');

            return self::SUCCESS;
        }

        $this->info("Каналов к сбору: {$channels->count()} (окно {$days} дн.{$filter})");

        foreach ($channels as $channel) {
            if ($this->option('sync')) {
                $this->line("→ {$channel->dzen_key} ({$channel->title})");
                $job = new CrawlChannel($channel->id, $days);
                $job->handle(app(DzenCrawler::class));
                $this->info("  ✓ {$channel->dzen_key}: обновлён ({$channel->refresh()->last_crawled_at?->format('H:i')})");
            } else {
                CrawlChannel::dispatch($channel->id, $days);
                $this->line("→ job отправлен: {$channel->dzen_key}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Канал по ID или dzen_key; own-канал дополняется своими активными конкурентами.
     * Явно указанный канал собирается даже если неактивен (с предупреждением).
     *
     * @return Collection<int, Channel>|null
     */
    private function resolveChannels(string $ref): ?Collection
    {
        $channel = ctype_digit($ref)
            ? Channel::find((int) $ref)
            : Channel::where('dzen_key', $ref)->first();

        if ($channel === null) {
            $this->error("Канал не найден: {$ref} (укажите ID или dzen_key).");

            return null;
        }

        if (! $channel->is_active) {
            $this->warn("Канал {$channel->dzen_key} неактивен — собираю по явному запросу.");
        }

        $channels = collect([$channel]);

        if ($channel->is_own) {
            $competitors = $channel->competitors()->where('is_active', true)->get();
            $channels = $channels->merge($competitors);
        }

        return $channels->unique('id')->sortByDesc('is_own')->values();
    }
}
