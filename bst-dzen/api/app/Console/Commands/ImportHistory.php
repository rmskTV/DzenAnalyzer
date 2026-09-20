<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Post;
use App\Models\PostSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Импорт исторических данных из CSV выгрузки python-прототипа.
 * Создаёт справочник каналов, конкурентный набор БСТ, посты и первый снапшот.
 */
class ImportHistory extends Command
{
    protected $signature = 'dzen:import-history
        {--path=/var/www/data/all_channels.csv : путь к all_channels.csv}';

    protected $description = 'Импорт исторических постов из CSV прототипа';

    /** Метаданные каналов выгрузки прототипа */
    private const CHANNEL_META = [
        'bst24bratsk' => ['key' => 'bst24bratsk', 'mode' => 'name', 'title' => 'БСТ — Братская студия телевидения', 'tz' => 'Asia/Irkutsk', 'own' => true, 'subs' => 6310],
        'irk.kp.ru' => ['key' => 'irk.kp.ru', 'mode' => 'name', 'title' => 'Комсомольская правда — Иркутск', 'tz' => 'Asia/Irkutsk', 'own' => false, 'subs' => 70129],
        'irk.ru' => ['key' => 'irk.ru', 'mode' => 'name', 'title' => 'Ирк.ру', 'tz' => 'Asia/Irkutsk', 'own' => false, 'subs' => 7283],
        'irk.aif.ru' => ['key' => 'irk.aif.ru', 'mode' => 'name', 'title' => 'АиФ — Иркутск', 'tz' => 'Asia/Irkutsk', 'own' => false, 'subs' => 10800],
        'nts' => ['key' => '61b8a3e21f0d0544aaffa63c', 'mode' => 'id', 'title' => 'НТС — Новое Телевидение Сибири', 'tz' => 'Asia/Irkutsk', 'own' => false, 'subs' => 5476],
        'gorodprima.ru' => ['key' => 'gorodprima.ru', 'mode' => 'name', 'title' => 'Город Прима (Красноярск)', 'tz' => 'Asia/Krasnoyarsk', 'own' => false, 'subs' => 6842],
        'livennov' => ['key' => 'livennov', 'mode' => 'name', 'title' => 'Лайв Новс (Нижний Новгород)', 'tz' => 'Europe/Moscow', 'own' => false, 'subs' => 16101],
    ];

    /** Конкурентный набор БСТ: только каналы Иркутской области */
    private const BST_COMPETITORS = ['irk.kp.ru', 'irk.ru', 'irk.aif.ru', 'nts'];

    public function handle(): int
    {
        $path = (string) $this->option('path');
        if (! is_file($path)) {
            $this->error("Файл не найден: {$path}");

            return self::FAILURE;
        }

        $ids = $this->ensureChannels();
        $own = Channel::where('is_own', true)->firstOrFail();

        // конкурентный набор
        $competitorIds = array_map(fn ($label) => $ids[$label], self::BST_COMPETITORS);
        $own->competitors()->syncWithoutDetaching($competitorIds);
        $this->info('Конкуренты БСТ: ' . count($competitorIds));

        // посты
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]); // BOM utf-8-sig
        $postsCreated = 0;
        $snapshotsCreated = 0;
        $snapshotDate = now()->toDateString();

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);
            $label = $data['channel'];
            if (! isset($ids[$label])) {
                continue;
            }

            $url = trim($data['url']);
            $post = Post::updateOrCreate(
                ['channel_id' => $ids[$label], 'url' => $url],
                [
                    'dzen_post_id' => basename(parse_url($url, PHP_URL_PATH) ?: ''),
                    'type' => in_array($data['type'], ['article', 'video_long', 'short'], true) ? $data['type'] : 'article',
                    'title' => $data['title'],
                    'lead' => $data['lead'] !== '' ? $data['lead'] : null,
                    'published_at' => Carbon::parse($data['published_at']),
                    'views' => (int) $data['views'],
                    'comments' => (int) $data['comments'],
                    'size_sec' => (int) $data['size_sec'],
                ],
            );
            $postsCreated++;

            PostSnapshot::updateOrCreate(
                ['post_id' => $post->id, 'snapshot_date' => $snapshotDate],
                ['views' => $post->views, 'comments' => $post->comments],
            );
            $snapshotsCreated++;
        }
        fclose($handle);

        $this->info("Постов: {$postsCreated}, снапшотов: {$snapshotsCreated}");
        $this->info('Импорт завершён.');

        return self::SUCCESS;
    }

    /** @return array<string, int> label => channel_id */
    private function ensureChannels(): array
    {
        $ids = [];
        foreach (self::CHANNEL_META as $label => $meta) {
            $channel = Channel::updateOrCreate(
                ['dzen_mode' => $meta['mode'], 'dzen_key' => $meta['key']],
                [
                    'title' => $meta['title'],
                    'timezone' => $meta['tz'],
                    'is_own' => $meta['own'],
                    'is_active' => true,
                    'subscribers' => $meta['subs'],
                ],
            );
            $ids[$label] = $channel->id;
        }
        $this->info('Каналы: ' . count($ids));

        return $ids;
    }
}
