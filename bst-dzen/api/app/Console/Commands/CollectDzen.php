<?php

namespace App\Console\Commands;

use App\Jobs\CrawlChannel;
use App\Models\Channel;
use App\Services\Dzen\DzenCrawler;
use Illuminate\Console\Command;

/**
 * Ежедневный сбор: все активные каналы -> посты + снапшоты просмотров.
 * По умолчанию окно 21 день — совпадает с окном отчётов, просмотры постов
 * обновляются всю их жизнь в отчёте.
 */
class CollectDzen extends Command
{
    protected $signature = 'dzen:collect
        {--days=21 : глубина окна сбора}
        {--sync : выполнять без очереди (последовательно)}';

    protected $description = 'Собрать публикации активных каналов Дзена + снапшоты';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $channels = Channel::where('is_active', true)->orderByDesc('is_own')->get();

        if ($channels->isEmpty()) {
            $this->warn('Нет активных каналов.');

            return self::SUCCESS;
        }

        $this->info("Каналов к сбору: {$channels->count()} (окно {$days} дн.)");

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
}
