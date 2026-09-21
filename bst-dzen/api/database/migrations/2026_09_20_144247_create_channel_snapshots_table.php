<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->unsignedInteger('subscribers');
            $table->timestamps();

            $table->unique(['channel_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_snapshots');
    }
};
