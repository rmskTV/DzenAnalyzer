<?php

namespace App\Services\LLM;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Клиент LLM через Bothub (OpenAI-совместимый API).
 * API-ключ — в .env (LLM_API_KEY/LLM_BASE_URL), модели по операциям —
 * в settings (ключ 'llm': {models: {classify, rewrite, evergreen, rules}}).
 */
class LlmClient
{
    public const OPERATIONS = ['classify', 'rewrite', 'evergreen', 'rules'];

    public function isConfigured(): bool
    {
        return (string) config('services.bothub.api_key') !== ''
            && (string) config('services.bothub.base_url') !== '';
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @return string ответ ассистента
     *
     * @throws LlmException
     */
    public function chat(string $operation, array $messages, float $temperature = 0.7, bool $jsonMode = false): string
    {
        if (! $this->isConfigured()) {
            throw new LlmException('LLM не настроен: задайте LLM_BASE_URL и LLM_API_KEY в .env');
        }

        $options = [
            'model' => $this->modelFor($operation),
            'messages' => $messages,
            'temperature' => $temperature,
        ];
        if ($jsonMode) {
            $options['response_format'] = ['type' => 'json_object'];
        }

        $response = Http::withToken((string) config('services.bothub.api_key'))
            ->timeout(180)
            ->retry(2, 5000, throw: false)
            ->post(rtrim((string) config('services.bothub.base_url'), '/') . '/chat/completions', $options);

        if ($response->failed()) {
            throw new LlmException("LLM HTTP {$response->status()}: " . mb_substr($response->body(), 0, 300));
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || $content === '') {
            throw new LlmException('Пустой ответ LLM');
        }

        return $content;
    }

    /** Ответ с декодированием JSON (устойчив к ```json-обёрткам) */
    public function chatJson(string $operation, array $messages, float $temperature = 0.7): array
    {
        $raw = $this->chat($operation, $messages, $temperature, true);
        $raw = preg_replace('/^```(?:json)?|```$/m', '', trim($raw));

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new LlmException('LLM вернул некорректный JSON: ' . mb_substr($raw, 0, 200));
        }

        return $decoded;
    }

    public function modelFor(string $operation): string
    {
        $models = \App\Models\Setting::where('key', 'llm')->value('value')['models'] ?? [];

        return $models[$operation]
            ?? $models['default']
            ?? (string) config('services.bothub.default_model');
    }
}

class LlmException extends RuntimeException
{
}
