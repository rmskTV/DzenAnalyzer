<?php

namespace Tests\Unit;

use App\Models\Channel;
use App\Services\Dzen\DzenApiClient;
use App\Services\Dzen\DzenCrawler;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class DzenCrawlerTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mockery::close();
    }

    /**
     * Закреплённый пост с датой из прошлого не должен обрывать пагинацию:
     * за страницей с таким выбросом идут ещё актуальные посты окна.
     */
    public function test_outlier_old_post_does_not_stop_pagination(): void
    {
        $mk = fn (int $daysAgo, string $i) => [
            'type' => 'article',
            'title' => "post-{$i}",
            'publicationDate' => Carbon::now()->subDays($daysAgo)->getTimestamp(),
            'views' => 100,
            'shareLink' => "https://dzen.ru/a/{$i}",
        ];
        $link = fn (string $cursor) => ['more' => ['link' => "https://dzen.ru/api/x?next_page_id={$cursor}"]];

        // страница 1: свежие + закреплённый 40-дневной давности (старое условие ломалось здесь)
        $page1 = ['items' => [$mk(1, 'a'), $mk(2, 'b'), $mk(40, 'pinned')]] + $link('p2');
        // страница 2: актуальные посты середины окна — раньше терялись
        $page2 = ['items' => [$mk(3, 'c'), $mk(4, 'd'), $mk(5, 'e')]] + $link('p3');
        // страница 3: целиком за окном -> корректная остановка
        $page3 = ['items' => [$mk(30, 'x'), $mk(31, 'y')]];

        $pages = [$page1, $page2, $page3];
        $api = \Mockery::mock(DzenApiClient::class);
        $api->shouldReceive('fetchByName')->times(3)->andReturnUsing(function () use (&$pages) {
            return array_shift($pages);
        });

        $channel = new Channel(['dzen_mode' => 'name', 'dzen_key' => 'gorodprima.ru']);
        $rows = (new DzenCrawler($api))->crawl($channel, 21);

        $this->assertSame(
            ['post-a', 'post-b', 'post-c', 'post-d', 'post-e'],
            collect($rows)->pluck('title')->values()->all(),
        );
        $this->assertNotContains('post-pinned', collect($rows)->pluck('title')->all());
    }
}
