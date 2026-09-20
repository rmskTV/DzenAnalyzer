<?php

namespace App\Console\Commands;

use App\Services\Feedback\RulesEngine;
use App\Services\LLM\LlmClient;
use Illuminate\Console\Command;

class ProposeRules extends Command
{
    protected $signature = 'dzen:rules';

    protected $description = 'Предложить новую версию правил рерайта по статистике (неактивна до одобрения)';

    public function handle(): int
    {
        if (! app(LlmClient::class)->isConfigured()) {
            $this->warn('LLM не настроен — предложение правил пропущено.');

            return self::SUCCESS;
        }

        $version = app(RulesEngine::class)->proposeGlobal();

        if (! $version) {
            $this->info('Недостаточно опубликованных постов со статистикой (нужно >= 5).');

            return self::SUCCESS;
        }

        $this->info("Создана версия правил #{$version->id}: {$version->title}");
        $this->line($version->summary ?? '');

        return self::SUCCESS;
    }
}
