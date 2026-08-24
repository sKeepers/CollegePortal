<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Протокол ГИА: то, на что опираются приказ о выпуске и выгрузка в ФРДО.
 *
 * До сих пор государственная итоговая аттестация была **строкой экзамена** с типом `gia`.
 * Для расписания сессии этого хватает, для документа об образовании — нет: приказ о выпуске
 * и ФРДО требуют номер и дату протокола, председателя комиссии и решение по каждому
 * выпускнику, а всё это жило одним свободным полем `diplomas.gia_decision`.
 *
 * Свободное поле нельзя ни проверить, ни выгрузить, ни сверить с приказом. Поэтому решение
 * по каждому выпускнику становится **строкой**, а диплом на неё ссылается вместо того,
 * чтобы её пересказывать.
 *
 * **Состав комиссии — список в JSON, и это осознанно.** Председатель и секретарь названы
 * отдельными колонками: их требуют документы поимённо. Остальные члены комиссии — как
 * правило люди со стороны, карточек в портале у них нет и заводить их незачем; для
 * печатной шапки протокола достаточно фамилии и должности. Решение по выпускнику — другое
 * дело: оно уходит в ФРДО и в приказ, и структурой оно быть обязано.
 *
 * Идемпотентна: повторный запуск ничего не добавляет.
 */
return new class extends Migration
{
    private const CODE = 'graduation.gia_protocols';

    /** Те же роли, что у выпуска и дипломов. */
    private const ROLES = ['admin', 'study_records', 'deputy', 'academic_office'];

    public function up(): void
    {
        if (! Schema::hasTable('gia_protocols')) {
            Schema::create('gia_protocols', function (Blueprint $table): void {
                $table->id();
                $table->string('number', 50);
                $table->date('protocol_date');
                $table->string('academic_year', 9);

                // Протокол ведут по группе, но группа может быть удалена позже выпуска:
                // ссылка обнуляется, а название группы остаётся строкой — документ обязан
                // читаться и через десять лет, когда группы уже нет.
                $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
                $table->string('group_name')->nullable();
                $table->foreignId('education_program_id')->nullable()->constrained()->nullOnDelete();

                $table->string('chairman');
                $table->string('chairman_position')->nullable();
                $table->string('secretary')->nullable();

                /** @see описание класса: члены комиссии — печатная шапка, не сущность */
                $table->json('members')->nullable();

                $table->string('status', 16)->default('draft');
                $table->string('note', 2000)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['number', 'academic_year'], 'gia_protocols_number_unique');
            });
        }

        if (! Schema::hasTable('gia_protocol_decisions')) {
            Schema::create('gia_protocol_decisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('gia_protocol_id')->constrained()->cascadeOnDelete();
                $table->foreignId('student_id')->constrained()->cascadeOnDelete();

                // Фамилия записана рядом со ссылкой по той же причине, что и название
                // группы: протокол — документ, и он обязан читаться сам по себе.
                $table->string('student_name');

                // «Присвоить квалификацию» или «отказать»: решение, а не оценка.
                $table->string('result', 16)->default('passed');
                $table->string('mark', 32)->nullable();
                $table->string('qualification')->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamps();

                $table->unique(['gia_protocol_id', 'student_id'], 'gia_decisions_unique');
            });
        }

        if (! Schema::hasColumn('diplomas', 'gia_protocol_decision_id')) {
            Schema::table('diplomas', function (Blueprint $table): void {
                // Диплом ссылается на решение, а не пересказывает его. Старое свободное
                // поле `gia_decision` остаётся: в нём лежат уже выданные дипломы, и
                // переписывать историю нельзя.
                $table->foreignId('gia_protocol_decision_id')->nullable()->after('gia_decision')
                    ->constrained('gia_protocol_decisions')->nullOnDelete();
            });
        }

        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'module' => 'Graduation',
                'code' => self::CODE,
                'name' => 'Выпуск: протоколы ГИА',
                'description' => 'Ведение протоколов государственной итоговой аттестации и решений по выпускникам.',
                'system' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (self::ROLES as $code) {
            $roleId = DB::table('roles')->where('code', $code)->value('id');

            if ($roleId !== null) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('diplomas', 'gia_protocol_decision_id')) {
            Schema::table('diplomas', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('gia_protocol_decision_id');
            });
        }

        Schema::dropIfExists('gia_protocol_decisions');
        Schema::dropIfExists('gia_protocols');

        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
