<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `ARCH-001`, шаг 3, подготовка: починить каталог прав, прежде чем снимать
 * право-зонтик.
 *
 * Снятие зонтика закрывает двери. Замер показал, что часть дверей закроется не
 * по решению владельца, а по расхождению между `RoleSeeder` и миграцией шага 2:
 *
 * - права `gate.points.manage` нет в каталоге установленной системы вовсе.
 *   Сидер его объявляет, но сидер выполняется только при установке, а миграция
 *   шага 2 ищет право по коду, не находит и молча пропускает выдачу. Корпуса и
 *   точки прохода три роли открывают сегодня только через зонтик;
 * - `import.manage` роль `study` получает в сидере (владелец подтвердил
 *   10.08.2026), а миграция шага 2 его не выдавала;
 * - `students.bulk_accounts` в каталоге установленной системы тоже нет.
 *
 * Заодно разводится само `import.manage`. Одно право открывало и загрузку
 * файлов, и очистку рабочих данных стенда — «загрузить студентов» и «стереть
 * базу» под одним ключом. Решение владельца от 10.08.2026: загрузка остаётся
 * учебной части, демонстрационные данные и очистка уходят под новое право
 * `demo_data.manage`, которое не выдаётся никому, кроме администратора.
 *
 * Идемпотентна: повторный запуск ничего не дублирует.
 */
return new class extends Migration
{
    /**
     * Права, которых может не быть в каталоге установленной системы.
     *
     * @var list<array{code:string,name:string,module:string,description:string}>
     */
    private const CATALOGUE = [
        [
            'code' => 'gate.points.manage',
            'name' => 'Проходная: корпуса и точки прохода',
            'module' => 'Identity',
            'description' => 'Ведение справочника корпусов и точек прохода.',
        ],
        [
            'code' => 'students.bulk_accounts',
            'name' => 'Студенты: массовая выдача учетных записей',
            'module' => 'Students',
            'description' => 'Создание учетных записей группе разом с одноразовым показом паролей.',
        ],
        [
            'code' => 'demo_data.manage',
            'name' => 'Демонстрационные данные и очистка стенда',
            'module' => 'System',
            'description' => 'Создание, очистка и выгрузка демонстрационного набора, очистка рабочих данных стенда.',
        ],
    ];

    /**
     * Выдача, которую шаг 2 задумывал, но до установленной системы не довёл.
     *
     * `demo_data.manage` здесь намеренно отсутствует: его получает только
     * администратор, а он проходит мимо прав через `Gate::before`.
     *
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        'gate.points.manage' => ['study', 'deputy', 'academic_office'],
        'import.manage' => ['study'],
        'students.bulk_accounts' => ['study_records', 'deputy', 'academic_office'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::CATALOGUE as $permission) {
            if (DB::table('permissions')->where('code', $permission['code'])->exists()) {
                continue;
            }

            DB::table('permissions')->insert($permission + [
                'system' => true,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach (self::GRANTS as $code => $roleCodes) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');

            if ($permissionId === null) {
                continue;
            }

            $existing = DB::table('permission_role')->where('permission_id', $permissionId)->pluck('role_id')->all();

            $rows = DB::table('roles')
                ->whereIn('code', $roleCodes)
                ->pluck('id')
                ->reject(fn (int $roleId): bool => in_array($roleId, $existing, true))
                ->map(fn (int $roleId): array => ['role_id' => $roleId, 'permission_id' => $permissionId])
                ->all();

            if ($rows !== []) {
                DB::table('permission_role')->insert($rows);
            }
        }
    }

    /**
     * Обратный ход снимает выданное и убирает право, заведённое здесь впервые.
     *
     * `gate.points.manage` и `students.bulk_accounts` остаются: их объявляет
     * `RoleSeeder`, и на системе, установленной с нуля, они появились не отсюда.
     * Удалять их значило бы вычистить чужую запись каталога.
     */
    public function down(): void
    {
        foreach (self::GRANTS as $code => $roleCodes) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');

            if ($permissionId === null) {
                continue;
            }

            DB::table('permission_role')
                ->where('permission_id', $permissionId)
                ->whereIn('role_id', DB::table('roles')->whereIn('code', $roleCodes)->pluck('id'))
                ->delete();
        }

        $demoDataId = DB::table('permissions')->where('code', 'demo_data.manage')->value('id');

        if ($demoDataId !== null) {
            DB::table('permission_role')->where('permission_id', $demoDataId)->delete();
            DB::table('permissions')->where('id', $demoDataId)->delete();
        }
    }
};
