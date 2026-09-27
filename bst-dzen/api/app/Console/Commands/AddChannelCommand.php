<?php

namespace App\Console\Commands;

use App\Models\Channel;
use Illuminate\Console\Command;

/**
 * Добавление/обновление канала Дзена (idempotent по паре режим+ключ).
 * Название и подписчики подтягиваются при первом dzen:collect, если не заданы.
 *
 * Примеры:
 *   php artisan dzen:channel irk.ru --competitors-of=1
 *   php artisan dzen:channel 631af459b1da0113c4a58ff8 --tz=Asia/Krasnoyarsk
 *   php artisan dzen:channel mychannel --own --title="Мой канал"
 */
class AddChannelCommand extends Command
{
    protected $signature = 'dzen:channel
        {key : имя канала из URL (dzen.ru/<key>) или 24-hex channel_id}
        {--title= : название (по умолчанию = ключ; подтянется из Дзена при первом сборе)}
        {--id : ключ — 24-hex channel_id (иначе определяется автоматически)}
        {--own : пометить как собственный канал}
        {--tz= : часовой пояс канала (по умолчанию Asia/Irkutsk)}
        {--competitors-of= : ID own-канала, в конкурентный набор которого добавить}';

    protected $description = 'Добавить/обновить канал Дзена';

    public function handle(): int
    {
        $key = trim((string) $this->argument('key'));

        $mode = $this->option('id') || preg_match('/^[0-9a-f]{24}$/', $key) === 1 ? 'id' : 'name';

        $tz = (string) ($this->option('tz') ?: 'Asia/Irkutsk');
        if (! in_array($tz, timezone_identifiers_list(), true)) {
            $this->error("Неизвестный часовой пояс: {$tz} (примеры: Asia/Irkutsk, Asia/Krasnoyarsk)");

            return self::FAILURE;
        }

        $ownId = (int) $this->option('competitors-of');

        $channel = Channel::query()->where(['dzen_mode' => $mode, 'dzen_key' => $key])->first();

        if ($channel === null) {
            $channel = Channel::create([
                'dzen_key' => $key,
                'dzen_mode' => $mode,
                'title' => (string) ($this->option('title') ?: $key),
                'timezone' => $tz,
                'is_own' => (bool) $this->option('own'),
                'is_active' => true,
            ]);
            $created = true;
        } else {
            $created = false;

            $updates = [];
            if ($title = (string) $this->option('title')) {
                $updates['title'] = $title;
            }
            if ($this->option('tz')) {
                $updates['timezone'] = $tz;
            }
            if ($this->option('own')) {
                $updates['is_own'] = true;
            }
            if ($updates !== []) {
                $channel->fill($updates)->save();
            }
        }

        if ($ownId > 0) {
            $own = $ownId === $channel->id ? null : Channel::own()->find($ownId);

            if ($own === null) {
                $this->error("Канал {$ownId} не найден или не является own-каналом (нельзя указать самого себя).");

                return self::FAILURE;
            }

            $own->competitors()->syncWithoutDetaching([$channel->id]);
        }

        $this->info(sprintf(
            '%s #%d %s (mode: %s, own: %s, tz: %s%s)',
            $created ? 'Добавлен канал' : 'Обновлён канал',
            $channel->id,
            $channel->title,
            $mode,
            $channel->is_own ? 'да' : 'нет',
            $channel->timezone,
            $ownId > 0 ? ", конкурентный набор #{$ownId}" : '',
        ));
        $this->line('Данные подтянет ближайший dzen:collect (или запустите: make collect).');

        return self::SUCCESS;
    }
}
