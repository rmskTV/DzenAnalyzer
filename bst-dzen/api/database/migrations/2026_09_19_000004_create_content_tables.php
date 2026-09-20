<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('own_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('own_channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('published_post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->enum('source_type', ['site_rewrite', 'external_rewrite', 'evergreen', 'original']);
            $table->text('source_url')->nullable();
            $table->string('title');
            $table->enum('title_variant', ['A', 'B', 'C'])->nullable();
            $table->string('headline_pattern')->nullable();   // тег эксперимента: colon/question/number...
            $table->longText('body');
            $table->string('rubric', 64)->nullable();
            $table->enum('status', ['generated', 'edited', 'approved', 'queued', 'published', 'rejected'])
                ->default('generated');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('experiment_tags')->nullable();
            $table->timestamps();

            $table->index(['own_channel_id', 'status']);
        });

        Schema::create('rule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('own_channel_id')->nullable()->constrained('channels')->cascadeOnDelete(); // null = глобальный слой
            $table->enum('layer', ['global', 'channel']);
            $table->string('title');
            $table->longText('content');                    // markdown-правила
            $table->text('summary')->nullable();            // что изменилось и почему
            $table->boolean('is_active')->default(false);
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['layer', 'own_channel_id', 'is_active']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->index();
            $table->foreignId('own_channel_id')->nullable()->constrained('channels')->cascadeOnDelete(); // null = глобальная
            $table->json('value');
            $table->timestamps();

            $table->unique(['key', 'own_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('rule_versions');
        Schema::dropIfExists('own_publications');
    }
};
