<?php

namespace App\Services\Dzen;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Клиент неофициального API dzen.ru/api/web/v1/channel-more.
 *
 * Два режима:
 *  - name: канал по channel_name, тройной курсор (articles/…,longs/…,shorts/…)
 *  - id:   канал по channel_id + tab (articles|longs|shorts), одиночный курсор
 *
 * Сессии/CSRF не требуются: GET-запросы проверяются без токенов
 * (проверено серийной эксплуатацией python-прототипа).
 */
class DzenApiClient
{
    public const API_URL = 'https://dzen.ru/api/web/v1/channel-more';

    private const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    private const BASE_PARAMS = [
        'sort_type' => 'regular',
        'country_code' => 'ru',
        'clid' => '1400',
        'lang' => 'ru',
    ];

    /**
     * @param  array<string, mixed>  $extra  next_page_id, channel_name / channel_id+tab
     * @return array<string, mixed> декодированный JSON-ответ
     *
     * @throws DzenApiException
     */
    public function fetch(array $extra): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => self::UA])
                ->timeout(30)
                ->retry(3, 2000, throw: false)
                ->get(self::API_URL, array_merge(self::BASE_PARAMS, $extra));
        } catch (ConnectionException $e) {
            throw new DzenApiException("Сеть недоступна: {$e->getMessage()}", 0, $e);
        }

        if ($response->status() === 429 || $response->status() === 403) {
            throw new DzenApiException("Дзен отклонил запрос: HTTP {$response->status()} (троттлинг?)");
        }

        try {
            $json = $response->throw()->json();
        } catch (RequestException $e) {
            throw new DzenApiException("HTTP {$e->response->status()} от Дзен API", 0, $e);
        }

        if (! is_array($json)) {
            throw new DzenApiException('Неожиданный формат ответа Дзен API');
        }

        return $json;
    }

    /** Страница для канала в режиме channel_name */
    public function fetchByName(string $channelName, ?string $nextPageId): array
    {
        return $this->fetch(array_filter([
            'channel_name' => $channelName,
            'next_page_id' => $nextPageId,
        ]));
    }

    /** Страница для канала в режиме channel_id (вкладка articles|longs|shorts) */
    public function fetchById(string $channelId, string $tab, ?string $nextPageId): array
    {
        return $this->fetch(array_filter([
            'channel_id' => $channelId,
            'tab' => $tab,
            'next_page_id' => $nextPageId,
        ]));
    }
}
