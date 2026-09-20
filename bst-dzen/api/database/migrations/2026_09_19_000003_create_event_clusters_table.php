<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Кластеры-события; scope = own-канал, в конкурентном наборе которого
        // искались совпадения (одни посты могут входить в кластеры разных скоупов)
        Schema::create('event_clusters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scope_channel_id')->constrained('channels')->cascadeOnDelete();
            $table->timestamp('first_published_at');
            $table->unsignedTinyInteger('n_channels')->default(2);
            $table->text('title')->nullable();           // заголовок первоисточника
            $table->timestamps();

            $table->index(['scope_channel_id', 'first_published_at']);
        });

        Schema::create('event_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_cluster_id')->constrained('event_clusters')->cascadeOnDelete();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->unsignedInteger('delay_min')->default(0); // отставание от первоисточника
            $table->timestamps();

            $table->unique(['event_cluster_id', 'post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_members');
        Schema::dropIfExists('event_clusters');
    }
};
