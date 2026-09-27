<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Реестр рубрик: системные + создаваемые LLM (с дедупом по имени)
        Schema::create('rubrics', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->enum('created_by', ['system', 'llm'])->default('system');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach ([
            'Происшествия', 'Власть/политика', 'ЖКХ/город', 'Транспорт',
            'Экономика/выплаты', 'Природа/животные', 'Спорт', 'Культура/досуг',
            'Люди/общество', 'Развлечения/лайфстайл',
        ] as $name) {
            DB::table('rubrics')->insert(['name' => $name, 'created_by' => 'system', 'created_at' => now(), 'updated_at' => now()]);
        }

        // Реестр форматов: расширяется только администратором.
        // is_evergreen — производное свойство формата (не привязан к дате).
        Schema::create('formats', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->boolean('is_evergreen')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach ([
            ['Заметка', false],
            ['Лонгрид', false],
            ['Интервью', false],
            ['Репортаж', false],
            ['Кейс', false],
            ['Дайджест', false],
            ['Подборка/Listicle', true],
            ['Гайд/Инструкция', true],
            ['Народный календарь', true],
            ['Анонс/Афиша', false],
            ['Мнение/Аналитика', false],
        ] as [$name, $evergreen]) {
            DB::table('formats')->insert([
                'name' => $name, 'is_evergreen' => $evergreen,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // kind -> format
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('content_kind');
            $table->string('content_format', 64)->nullable()->after('rubric');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('content_format');
            $table->string('content_kind', 16)->nullable()->after('rubric');
        });
        Schema::dropIfExists('formats');
        Schema::dropIfExists('rubrics');
    }
};
