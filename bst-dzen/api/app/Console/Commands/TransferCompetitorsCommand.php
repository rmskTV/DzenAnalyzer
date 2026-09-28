<?php

namespace App\Console\Commands;

use App\Models\Channel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Полный трансфер конкурентного набора между own-каналами (объединение с набором получателя).
 *
 * Пример:
 *   php artisan dzen:transfer-competitors 1 2
 */
class TransferCompetitorsCommand extends Command
{
    protected $signature = 'dzen:transfer-competitors
        {from : ID старого own-канала}
        {to : ID нового own-канала}';

    protected $description = 'Перенести всех конкурентов с одного own-канала на другой (объединение наборов)';

    public function handle(): int
    {
        $fromId = (int) $this->argument('from');
        $toId = (int) $this->argument('to');

        if ($fromId === $toId) {
            $this->error("Каналы должны различаться: from и to совпадают ({$fromId}).");

            return self::FAILURE;
        }

        $from = Channel::own()->find($fromId);
        $to = Channel::own()->find($toId);

        if ($from === null || $to === null) {
            $missing = $from === null ? $fromId : $toId;

            $this->error("Канал {$missing} не найден или не является own-каналом.");

            return self::FAILURE;
        }

        /** @var array{int, int} $result */
        $result = DB::transaction(function () use ($from, $to): array {
            $competitorIds = $from->competitors()->pluck('channels.id');
            $existingIds = $to->competitors()->pluck('channels.id');

            $to->competitors()->syncWithoutDetaching($competitorIds->all());
            $from->competitors()->detach();

            return [
                $competitorIds->diff($existingIds)->count(),
                $competitorIds->intersect($existingIds)->count(),
            ];
        });
        [$moved, $skipped] = $result;

        $this->info(sprintf(
            'Перенесено %d конкурентов (дублей пропущено: %d) с канала #%d на канал #%d.',
            $moved,
            $skipped,
            $from->id,
            $to->id,
        ));

        return self::SUCCESS;
    }
}
