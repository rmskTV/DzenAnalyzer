<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('rubric', 64)->nullable()->after('type');
            $table->string('content_kind', 16)->nullable()->after('rubric'); // news|evergreen
        });

        Schema::table('own_publications', function (Blueprint $table) {
            $table->double('result_vpd')->nullable()->after('experiment_tags');
            $table->timestamp('last_checked_at')->nullable()->after('result_vpd');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['rubric', 'content_kind']);
        });
        Schema::table('own_publications', function (Blueprint $table) {
            $table->dropColumn(['result_vpd', 'last_checked_at']);
        });
    }
};
