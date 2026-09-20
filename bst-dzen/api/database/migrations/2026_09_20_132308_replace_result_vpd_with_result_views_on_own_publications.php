<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('own_publications', function (Blueprint $table) {
            $table->dropColumn('result_vpd');
            $table->double('result_views')->nullable()->after('experiment_tags');
        });
    }

    public function down(): void
    {
        Schema::table('own_publications', function (Blueprint $table) {
            $table->dropColumn('result_views');
            $table->double('result_vpd')->nullable()->after('experiment_tags');
        });
    }
};
