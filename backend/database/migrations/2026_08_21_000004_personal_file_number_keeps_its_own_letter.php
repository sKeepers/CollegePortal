<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Буква личного дела хранится, а не вычисляется из фамилии.
 *
 * У каждой буквы алфавита своя нумерация, и вчера буква выводилась из текущей
 * фамилии студента. Владелец 21.08.2026 показал случай, на котором это неверно:
 * студентка с номером 115 была Ильясовой, вышла замуж и стала Черковой — **номер
 * остался прежним**, потому что он закреплён за делом, а не за фамилией. Её 115
 * принадлежит букве «И», и с номером 115 у другой студентки на «Ч» не спорит.
 *
 * Вычисляемая буква давала бы здесь ложный конфликт и, что хуже, меняла бы
 * принадлежность номера при каждой смене фамилии — то есть переписывала бы
 * прошлое. Поэтому буква записывается один раз, при заведении дела, и дальше не
 * пересчитывается.
 *
 * Существующим карточкам буква проставляется из фамилии: другого источника для
 * них нет, а на момент миграции все они заведены сегодня и фамилий не меняли.
 * Случай Черковой правится в её карточке руками — миграция угадывать не может.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->char('personal_file_letter', 1)->nullable()->after('personal_file_number');
            $table->index(['personal_file_letter', 'personal_file_number'], 'students_personal_file_index');
        });

        // Заполняем то, что уже заведено. `left()` есть и в PostgreSQL, и в
        // SQLite, но пишется по-разному, поэтому идём построчно: карточек с
        // номером на этот момент единицы.
        DB::table('students')
            ->whereNotNull('personal_file_number')
            ->whereNull('personal_file_letter')
            ->select('id', 'last_name')
            ->orderBy('id')
            ->chunk(200, function ($students): void {
                foreach ($students as $student) {
                    $letter = mb_substr(trim((string) $student->last_name), 0, 1);

                    if ($letter !== '') {
                        DB::table('students')->where('id', $student->id)
                            ->update(['personal_file_letter' => mb_strtoupper($letter)]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropIndex('students_personal_file_index');
            $table->dropColumn('personal_file_letter');
        });
    }
};
