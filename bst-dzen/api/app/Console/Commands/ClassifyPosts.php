<?php

namespace App\Console\Commands;

use App\Models\Post;
use App\Services\Analysis\RubricClassifier;
use App\Services\LLM\LlmClient;
use Illuminate\Console\Command;

class ClassifyPosts extends Command
{
    protected $signature = 'dzen:classify
        {--days=3 : окно классификации}
        {--force : сбросить рубрики/форматы окна и классифицировать заново}';

    protected $description = 'Классифицировать рубрики и форматы постов (LLM или эвристика)';

    public function handle(): int
    {
        $query = Post::where('published_at', '>=', now()->subDays((int) $this->option('days')));

        if ($this->option('force')) {
            $n = (clone $query)->whereNotNull('rubric')->orWhereNotNull('content_format')->count();
            $this->components->warn("Сброс классификации окна: {$n} постов");
            $query->update(['rubric' => null, 'content_format' => null]);
        }

        $posts = (clone $query)->get();
        $pending = $posts->filter(fn ($p) => $p->rubric === null || $p->content_format === null);

        if ($pending->isEmpty()) {
            $this->info('Нет постов без классификации.');

            return self::SUCCESS;
        }

        $n = app(RubricClassifier::class)->classifyPending($pending->values());
        $this->info("Классифицировано: {$n} (режим: " . (app(LlmClient::class)->isConfigured() ? 'LLM' : 'эвристика') . ')');

        $still = Post::where('published_at', '>=', now()->subDays((int) $this->option('days')))
            ->whereNull('rubric')->count();
        if ($still > 0) {
            $this->warn("Остались без рубрики: {$still} (уйдут в следующий прогон)");
        }

        return self::SUCCESS;
    }
}
