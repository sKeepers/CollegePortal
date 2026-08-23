<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал проходов учится принимать чужие события — и принимать их дважды.
 *
 * Живое сканирование записывает проход ровно один раз: человек поднёс пропуск,
 * мы создали событие. Выборка журнала у контроллера СКУД устроена иначе — она
 * отдаёт отрезок времени, отрезки перекрываются, связь рвётся, выборку
 * повторяют. Без внешнего идентификатора каждая повторная выборка задваивает
 * проходы, а следом врут присутствие в здании, опоздания и ночные отсутствия в
 * общежитии — и врут молча, потому что дубль выглядит как настоящий проход.
 *
 * Замерено на копии журнала действующей СКУД (50 337 событий, 16 793 прохода):
 * идентификатор события там сквозной и уникальный по всей базе, так что пара
 * «источник + внешний идентификатор» закрывает вопрос целиком.
 *
 * Источник в паре нужен потому, что нумерация у каждого контроллера своя:
 * событие № 1 проходной и событие № 1 общежития — разные проходы. Живые
 * события оставляют оба поля пустыми, и уникальность их не касается: и в
 * PostgreSQL, и в SQLite NULL не конфликтует с NULL.
 *
 * `card_uid` здесь же и по той же причине. Событие хранит владельца через
 * цифровой пропуск, а у чужого журнала пропусков нет вовсе — есть номер карты.
 * Карты, не заведённой в портале, не соответствует никто, и без этой колонки
 * проход терял бы единственную зацепку: кто прошёл, выяснить потом было бы
 * уже не из чего.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_events', function (Blueprint $table): void {
            $table->string('external_source', 32)->nullable();
            $table->string('external_id', 64)->nullable();
            $table->string('card_uid', 32)->nullable();

            $table->unique(['external_source', 'external_id'], 'access_events_external_unique');
            $table->index(['card_uid', 'event_time']);
        });

        Schema::create('access_point_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 32);
            $table->string('external_id', 64);
            $table->foreignId('access_point_id')->constrained('access_points')->cascadeOnDelete();
            $table->string('direction', 16)->nullable();
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_point_devices');

        Schema::table('access_events', function (Blueprint $table): void {
            $table->dropUnique('access_events_external_unique');
            $table->dropIndex(['card_uid', 'event_time']);
            $table->dropColumn(['external_source', 'external_id', 'card_uid']);
        });
    }
};
