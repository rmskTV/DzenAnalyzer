<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('dzen_post_id')->nullable();   // последний сегмент URL
            $table->enum('type', ['article', 'video_long', 'short'])->default('article');
            $table->text('title');
            $table->text('lead')->nullable();
            $table->string('url');
            $table->timestamp('published_at')->index();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('comments')->default(0);
            $table->unsignedInteger('size_sec')->default(0);
            $table->timestamps();

            $table->unique(['channel_id', 'url']);
            $table->index(['channel_id', 'published_at']);
        });

        Schema::create('post_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('comments')->default(0);
            $table->timestamps();

            $table->unique(['post_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_snapshots');
        Schema::dropIfExists('posts');
    }
};
