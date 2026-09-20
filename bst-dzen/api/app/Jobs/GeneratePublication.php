<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\SourceMaterial;
use App\Services\Content\EvergreenGenerator;
use App\Services\Content\Rewriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Генерация одной публикации: рерайт материала или evergreen-сетка.
 * Материал помечается used только при успехе.
 */
class GeneratePublication implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(
        public readonly int $ownChannelId,
        public readonly string $kind, // 'material' | 'evergreen'
        public readonly ?int $materialId = null,
    ) {
    }

    public function handle(): void
    {
        $own = Channel::findOrFail($this->ownChannelId);

        if ($this->kind === 'evergreen') {
            app(EvergreenGenerator::class)->generate($own->id);
            logger()->info('dzen:generate evergreen создан', ['own' => $own->dzen_key]);

            return;
        }

        $material = SourceMaterial::findOrFail($this->materialId);
        $publication = app(Rewriter::class)->rewrite($material, $own->id);

        $material->forceFill([
            'status' => 'used',
            'own_publication_id' => $publication->id,
        ])->save();

        logger()->info('dzen:generate рерайт создан', [
            'material' => $material->external_id,
            'publication' => $publication->id,
        ]);
    }

    public function failed(Throwable $e): void
    {
        logger()->error('dzen:generate провален', [
            'kind' => $this->kind,
            'material_id' => $this->materialId,
            'error' => (string) $e,
        ]);

        if ($this->kind === 'material' && $this->materialId) {
            SourceMaterial::whereKey($this->materialId)->update(['status' => 'failed']);
        }
    }
}
