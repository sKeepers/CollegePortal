<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Справка студенту — номерной документ, и учёт его повторяет бумажный.
 *
 * Колледж ведёт реестр справок в таблице: № по порядку, ФИО, дата рождения,
 * специальность, приказ и его дата, **две** графы с номерами справок, дата
 * получения и подпись. Владелец принёс этот реестр 28.08.2026 вместе с двумя
 * образцами бланка. Повторяем существующий учёт, а не придумываем свой.
 *
 * Три решения, каждое оплачено чужой бедой:
 *
 * 1. **Строка хранит снимок напечатанного, а не ссылку на живую карточку.**
 *    Справка — документ: студента переведут на курс выше, группу переименуют,
 *    специальность уточнят, — а выданная справка обязана остаться такой, какой
 *    её подписал директор. Живые связи здесь означали бы, что документ меняется
 *    задним числом и доказать напечатанное нечем.
 * 2. **Номер целый, а не строка.** У бланков гознака номер строковый, потому что
 *    `0000123` не равно `123` — там ведущие нули значимы. В реестре справок
 *    номера сплошные целые: замерено на файле владельца — 1181 номер с 729 по
 *    1909, пропусков ноль, повторов ноль. Строка здесь дала бы сортировку, в
 *    которой «10» стоит между «1» и «2».
 * 3. **Уникальность номера — в базе.** Номер справки в двух местах сразу (в
 *    бумажном реестре и в портале) — ровно то, обо что область споткнулась днём
 *    28.08 на бланках дипломов. Индекс не даёт выдать один номер дважды даже
 *    двум операторам, нажавшим «Выдать» одновременно.
 *
 * Строки не удаляются: маршрута на удаление нет, запрет стоит в модели. Реестр,
 * из которого можно убрать строку, реестром не является — пропуск в нумерации
 * виден сразу, спрятанная строка нет.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        [
            'module' => 'Students',
            'code' => 'certificates.view',
            'name' => 'Справки студентам: просмотр',
            'description' => 'Просмотр реестра выданных справок и печать реестра.',
        ],
        [
            'module' => 'Students',
            'code' => 'certificates.manage',
            'name' => 'Справки студентам: выдача',
            'description' => 'Выдача справки студенту, печать бланка, отметка о получении.',
        ],
    ];

    /**
     * Кто выдаёт справки, тот и ведёт их реестр.
     *
     * Набор ролей взят у соседнего номерного документа — бланков дипломов
     * (миграция `2026_08_24_000001`), и это не «на всякий случай»: справку
     * студенту выписывает та же учебная часть, что ведёт выпуск. Список обязан
     * совпадать с `RoleSeeder` до строки, иначе обновлённый портал отличается
     * от свежепоставленного; это ловит `RightsArriveByMigrationTest`.
     */
    private const VIEW_ROLES = ['admin', 'director', 'deputy', 'academic_office', 'study', 'study_records'];

    private const MANAGE_ROLES = ['admin', 'deputy', 'academic_office', 'study_records'];

    public function up(): void
    {
        Schema::create('student_certificates', function (Blueprint $table): void {
            $table->id();
            // Ссылка на студента нужна для реестра и поиска, но не для печати:
            // печатается снимок ниже. `restrictOnDelete` — потому что удаление
            // студента не должно уносить выданный ему документ.
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number')->unique();
            $table->date('issued_on');
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Снимок напечатанного. Всё, что стоит в бланке, — здесь.
            $table->string('full_name', 255);
            $table->date('birth_date');
            $table->unsignedTinyInteger('course');
            $table->string('specialty', 255);
            $table->string('study_form', 50);
            $table->string('enrollment_order_number', 100);
            $table->date('enrollment_order_date');
            // Приказ о переводе — только у второго курса и старше. У первого
            // его нет и быть не может: переводить пока неоткуда.
            $table->string('transfer_order_number', 100)->nullable();
            $table->date('transfer_order_date')->nullable();
            $table->date('study_start');
            $table->date('study_end');

            // «Дата получения» и «Подпись» в бумажном реестре заполняются от
            // руки, когда студент забирает справку. В файле владельца они
            // пусты у всех 591 строки — значит графа нужна, а заполняется
            // позже и человеком.
            $table->date('received_on')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('issued_on');
            $table->index(['student_id', 'issued_on']);
        });

        foreach (self::PERMISSIONS as $permission) {
            if (! DB::table('permissions')->where('code', $permission['code'])->exists()) {
                DB::table('permissions')->insert($permission + [
                    'system' => true,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->grant('certificates.view', self::VIEW_ROLES);
        $this->grant('certificates.manage', self::MANAGE_ROLES);
    }

    public function down(): void
    {
        Schema::dropIfExists('student_certificates');

        foreach (self::PERMISSIONS as $permission) {
            $id = DB::table('permissions')->where('code', $permission['code'])->value('id');

            if ($id === null) {
                continue;
            }

            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }

    /** @param  list<string>  $roles */
    private function grant(string $code, array $roles): void
    {
        $permissionId = DB::table('permissions')->where('code', $code)->value('id');

        if ($permissionId === null) {
            return;
        }

        $rows = DB::table('roles')
            ->whereIn('code', $roles)
            ->pluck('id')
            ->map(fn (int $roleId): array => ['role_id' => $roleId, 'permission_id' => $permissionId])
            ->all();

        if ($rows !== []) {
            // insertOrIgnore, а не проверка с последующей вставкой: на PostgreSQL
            // упавший INSERT отравил бы всю транзакцию миграции.
            DB::table('permission_role')->insertOrIgnore($rows);
        }
    }
};
