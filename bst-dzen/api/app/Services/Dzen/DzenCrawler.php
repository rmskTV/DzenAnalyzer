<?php

namespace App\Services\Dzen;

use App\Models\Channel;
use Illuminate\Support\Carbon;

/**
 * Обход канала Дзена с пагинацией и извлечением публикаций
 * (статьи в items, видео в floor-контейнерах или напрямую в режиме channel_id).
 * Порт python-прототипа (dzen_crawler.py).
 */
class DzenCrawler
{
    /** tab floor-контейнера/вкладки -> тип публикации */
    private const TAB_TYPES = ['longs' => 'video_long', 'shorts' => 'short'];

    /** прямые item type из API -> тип публикации */
    private const ITEM_TYPES = [
        'gif' => 'video_long',
        'short_video' => 'short',
        'short_video_compact' => 'short',
    ];

    public function __construct(private readonly DzenApiClient $api) {}

    /** Мета канала из блока source последнего обхода: [title, subscribers] */
    public ?array $channelMeta = null;

    /**
     * Собрать публикации канала за последние $days дней (с дедупликацией по url).
     *
     * @param  callable(int $page, int $rowsOnPage, ?Carbon $oldest): void  $onPage
     * @return array<int, array{type: string, title: string, lead: ?string, url: string,
     *                          published_at: Carbon, views: int, comments: int, size_sec: int}>
     */
    public function crawl(Channel $channel, int $days, ?callable $onPage = null): array
    {
        $this->channelMeta = null;

        $rows = $channel->dzen_mode === 'id'
            ? $this->crawlByIdMode($channel, $days, $onPage)
            : $this->crawlByNameMode($channel, $days, $onPage);

        $unique = [];
        foreach ($rows as $row) {
            $unique[$row['url'] !== '' ? $row['url'] : $row['title']] = $row;
        }

        return array_values($unique);
    }

    private function crawlByNameMode(Channel $channel, int $days, ?callable $onPage): array
    {
        $nowMs = (int) (microtime(true) * 1000);
        $cursor = "articles/-1/{$nowMs},longs/-1/{$nowMs},shorts/3/{$nowMs}";

        return $this->paginate(
            $days,
            $onPage,
            $cursor,
            fn (string $next) => $this->api->fetchByName($channel->dzen_key, $next),
        );
    }

    private function crawlByIdMode(Channel $channel, int $days, ?callable $onPage): array
    {
        $rows = [];
        foreach (['articles', 'longs', 'shorts'] as $tab) {
            $nowMs = (int) (microtime(true) * 1000);
            $rows = array_merge($rows, $this->paginate(
                $days,
                $onPage,
                "-1/{$nowMs}",
                fn (string $next) => $this->api->fetchById($channel->dzen_key, $tab, $next),
            ));
        }

        return $rows;
    }

    /**
     * Цикл пагинации: первая страница по синтетическому курсору,
     * далее по next_page_id из more.link; стоп — когда самая старая
     * датированная публикация страницы уходит за окно $days.
     *
     * @param  callable(string $cursor): array  $fetchPage
     */
    private function paginate(int $days, ?callable $onPage, string $cursor, callable $fetchPage, int $maxPages = 200): array
    {
        $cutoff = Carbon::now()->subDays($days)->getTimestamp();
        $rows = [];
        $undatedPages = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            $payload = $fetchPage($cursor);
            $pageRows = $this->extractRows($payload);

            foreach ($pageRows as $row) {
                if ($row['published_at']->getTimestamp() >= $cutoff) {
                    $rows[] = $row;
                }
            }

            $dated = array_map(
                fn (array $r) => $r['published_at']->getTimestamp(),
                array_filter($pageRows, fn (array $r) => $r['published_at']->getTimestamp() > 0),
            );
            $oldest = $dated ? min($dated) : 0;
            $newest = $dated ? max($dated) : 0;
            // страницы без дат (promo/brief) не двигают условие остановки по возрасту —
            // считаем их и выходим, если идут подряд
            $undatedPages = $dated ? 0 : $undatedPages + 1;

            if ($onPage) {
                $onPage($page, count($pageRows), $oldest ? Carbon::createFromTimestamp($oldest) : null);
            }

            usleep(700_000); // пауза между страницами, как в прототипе

            $next = $this->nextPageId($payload);
            // лента не строго хронологична: закреплённые/промо посты с датой из
            // прошлого встречаются на ранних страницах. Останавливаемся, только
            // когда страница ЦЕЛИКОМ ушла за окно (даже самый свежий её пост),
            // иначе один старый выброс обрывал пагинацию с потерей хвоста окна
            if ($pageRows === [] || ($newest && $newest < $cutoff) || $next === null || $undatedPages >= 2) {
                break;
            }
            $cursor = $next;
        }

        return $rows;
    }

    private function nextPageId(array $payload): ?string
    {
        $link = (string) (data_get($payload, 'more.link') ?? '');
        if ($link === '') {
            return null;
        }
        $query = parse_url($link, PHP_URL_QUERY);
        if (! $query) {
            return null;
        }
        parse_str($query, $params);

        return isset($params['next_page_id']) && $params['next_page_id'] !== ''
            ? (string) $params['next_page_id']
            : null;
    }

    /** @return array<int, array{type: string, title: string, lead: ?string, url: string, published_at: Carbon, views: int, comments: int, size_sec: int}> */
    public function extractRows(array $payload): array
    {
        $rows = [];
        foreach ((array) data_get($payload, 'items', []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $this->captureMeta($item);

            $type = (string) data_get($item, 'type', '');

            // floor-контейнер: channel_long_video_floor / channel_short_video_floor
            if (str_starts_with($type, 'channel_') && isset($item['items'])) {
                $kind = self::TAB_TYPES[data_get($item, 'tab', '')] ?? $type;
                foreach ((array) $item['items'] as $video) {
                    if (is_array($video)) {
                        $this->captureMeta($video);
                        $this->pushRow($rows, $this->makeRow($video, $kind));
                    }
                }

                continue;
            }

            $kind = $type === 'article'
                ? 'article'
                : (self::ITEM_TYPES[$type] ?? $type);
            $this->pushRow($rows, $this->makeRow($item, $kind));
        }

        return $rows;
    }

    /** Блок source item'а несёт актуальную мету канала (название, подписчики) */
    private function captureMeta(array $item): void
    {
        if ($this->channelMeta !== null) {
            return;
        }

        $subscribers = (int) (data_get($item, 'source.subscribers') ?? 0);
        if ($subscribers > 0) {
            $this->channelMeta = [
                'title' => trim((string) data_get($item, 'source.title', '')),
                'subscribers' => $subscribers,
            ];
        }
    }

    /** Promo/brief-карточки без заголовка — не публикации, отбрасываем */
    private function pushRow(array &$rows, array $row): void
    {
        if ($row['title'] !== '') {
            $rows[] = $row;
        }
    }

    /** @return array{type: string, title: string, lead: ?string, url: string, published_at: Carbon, views: int, comments: int, size_sec: int} */
    private function makeRow(array $item, string $kind): array
    {
        $ts = data_get($item, 'publicationDate');

        return [
            'type' => $kind,
            'title' => trim((string) (data_get($item, 'title') ?? '')),
            'lead' => ($lead = trim((string) (data_get($item, 'text') ?? ''))) !== '' ? mb_substr($lead, 0, 200) : null,
            'url' => strtok((string) (data_get($item, 'shareLink') ?: data_get($item, 'link') ?: ''), '?') ?: '',
            'published_at' => $ts ? Carbon::createFromTimestamp((int) $ts) : Carbon::createFromTimestamp(0),
            'views' => (int) (data_get($item, 'views') ?? 0),
            'comments' => (int) (data_get($item, 'socialInfo.commentCount') ?? 0),
            'size_sec' => (int) (data_get($item, 'timeToReadSeconds') ?: data_get($item, 'video.duration') ?: 0),
        ];
    }
}
