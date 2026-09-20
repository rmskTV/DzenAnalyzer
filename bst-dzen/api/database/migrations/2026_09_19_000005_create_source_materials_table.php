<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Материалы из внешних источников (сайт БСТ, другие сайты, ТГ-каналы)
        // доставляются парсерами пользователя по HTTP. Сырьё для контент-конвейера.
        Schema::create('source_materials', function (Blueprint $table) {
            $table->id();
            $table->string('source');                          // идентификатор парсера: bst_site|<произвольный>
            $table->string('external_id')->nullable();
            $table->text('url')->nullable();
            $table->text('title');
            $table->longText('body')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->enum('status', ['new', 'used', 'skipped', 'failed'])->default('new');
            $table->foreignId('own_publication_id')->nullable()->constrained('own_publications')->nullOnDelete();
            $table->timestamps();

            $table->unique(['source', 'external_id']);
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_materials');
    }
};
