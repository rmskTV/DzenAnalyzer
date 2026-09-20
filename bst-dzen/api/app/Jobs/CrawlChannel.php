<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Post;
use App\Models\PostSnapshot;
use App\Services\Dzen\DzenApiException;
use App\Services\Dzen\DzenCrawler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class CrawlChannel implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 1800;

    public function __construct(
        public readonly int $channelId,
        public readonly int $days = 21,
    ) {}

    public function handle(DzenCrawler $crawler): void
    {
        $channel = Channel::findOrFail($this->channelId);
        $today = Carbon::now()->toDateString();

        $rows = $crawler->crawl($channel, $this->days);

        foreach ($rows as $row) {
            $post = Post::updateOrCreate(
                ['channel_id' => $channel->id, 'url' => $row['url']],
                [
                    'dzen_post_id' => basename(parse_url($row['url'], PHP_URL_PATH) ?: ''),
                    'type' => $row['type'],
                    'title' => $row['title'],
                    'lead' => $row['lead'],
                    'published_at' => $row['published_at'],
                    'views' => $row['views'],
                    'comments' => $row['comments'],
                    'size_sec' => $row['size_sec'],
                ],
            );

            PostSnapshot::updateOrCreate(
                ['post_id' => $post->id, 'snapshot_date' => $today],
                ['views' => $row['views'], 'comments' => $row['comments']],
            );
        }

        $channel->forceFill(['last_crawled_at' => now()])->save();

        logger()->info('dzen:collect канал', [
            'channel' => $channel->dzen_key,
            'rows' => count($rows),
        ]);
    }

    public function failed(Throwable $e): void
    {
        logger()->error('dzen:collect провален', [
            'channel_id' => $this->channelId,
            'error' => $e instanceof DzenApiException ? $e->getMessage() : (string) $e,
        ]);
    }
}
