<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\Analysis\EventClusterer;
use Illuminate\Console\Command;

class BuildEvents extends Command
{
    protected $signature = 'dzen:events {--days=21 : окно кластеризации}';

    protected $description = 'Перестроить кластеры событий (дуэли инфоповодов) для own-каналов';

    public function handle(): int
    {
        foreach (Channel::own()->where('is_active', true)->get() as $own) {
            $result = app(EventClusterer::class)->rebuild($own, (int) $this->option('days'));
            $this->info("{$own->dzen_key}: событий {$result['clusters']} из {$result['posts']} постов");
        }

        return self::SUCCESS;
    }
}
