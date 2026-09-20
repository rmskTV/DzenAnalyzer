<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\Feedback\PublicationTracker;
use Illuminate\Console\Command;

class TrackPublications extends Command
{
    protected $signature = 'dzen:track';

    protected $description = 'Связать наши публикации с постами Дзена и обновить результаты';

    public function handle(): int
    {
        foreach (Channel::own()->where('is_active', true)->get() as $own) {
            $stats = app(PublicationTracker::class)->track($own);
            $this->info("{$own->dzen_key}: связано {$stats['linked']}, обновлено {$stats['updated']}");
        }

        return self::SUCCESS;
    }
}
