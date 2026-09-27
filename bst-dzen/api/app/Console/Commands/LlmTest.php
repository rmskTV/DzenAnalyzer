<?php

namespace App\Console\Commands;

use App\Services\LLM\LlmClient;
use Illuminate\Console\Command;

class LlmTest extends Command
{
    protected $signature = 'dzen:llm-test';

    protected $description = 'Проверка связи с Bothub LLM (модель, короткий запрос)';

    public function handle(): int
    {
        $llm = app(LlmClient::class);

        if (! $llm->isConfigured()) {
            $this->error('LLM не настроен: заполните LLM_BASE_URL и LLM_API_KEY в api/.env');

            return self::FAILURE;
        }

        $this->line('Базовый URL: '.config('services.bothub.base_url'));

        foreach (['classify', 'rewrite', 'evergreen', 'rules'] as $operation) {
            $this->line("модель [{$operation}]: {$llm->modelFor($operation)}");
        }

        $start = microtime(true);
        try {
            $answer = $llm->chat('rewrite', [
                ['role' => 'user', 'content' => 'Ответь одним словом: связь есть?'],
            ], 0.0);
        } catch (\Throwable $e) {
            $this->error('Ошибка запроса: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Ответ: '.trim($answer));
        $this->info('Задержка: '.round((microtime(true) - $start), 1).' c');

        return self::SUCCESS;
    }
}
