<?php

use Illuminate\Support\Facades\Schedule;

// Ежедневный конвейер (время контейнера = UTC; 06:00 Иркутск = 22:00 UTC пред. дня)
Schedule::command('dzen:collect --days=21')->dailyAt('22:00:00');
Schedule::command('dzen:classify --days=3')->dailyAt('23:00:00');
Schedule::command('dzen:events --days=3')->dailyAt('00:00:00');
Schedule::command('dzen:ingest')->dailyAt('22:10:00');
Schedule::command('dzen:generate')->dailyAt('22:20:00');
Schedule::command('dzen:publish')->dailyAt('23:00:00');   // 07:00 Иркутск
Schedule::command('dzen:track')->dailyAt('23:30:00');

// Еженедельно: предложение новых правил рерайта (воскресенье 09:00 Иркутска)
Schedule::command('dzen:rules')->sundays()->at('01:00:00');
