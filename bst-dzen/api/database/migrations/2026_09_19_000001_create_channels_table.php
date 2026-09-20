<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('dzen_key');                 // имя канала или 24-hex channel_id
            $table->enum('dzen_mode', ['name', 'id'])->default('name');
            $table->string('title');
            $table->unsignedInteger('subscribers')->default(0);
            $table->string('timezone', 64)->default('Asia/Irkutsk');
            $table->boolean('is_active')->default(true);   // участвует в сборе/анализе
            $table->boolean('is_own')->default(false);     // собственный канал
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamps();

            $table->unique(['dzen_mode', 'dzen_key']);
        });

        Schema::create('channel_competitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('own_channel_id')->constrained('channels')->cascadeOnDelete();
            $table->foreignId('competitor_channel_id')->constrained('channels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['own_channel_id', 'competitor_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_competitors');
        Schema::dropIfExists('channels');
    }
};
