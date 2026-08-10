<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Удаление карточек идёт только через администратора.
 *
 * Правило владельца от 10.08.2026: роль, нашедшая ошибочно заведённую карточку,
 * помечает её на удаление и объясняет причину, а удаляет администратор. Бэкенд
 * заявок и корзина были заложены раньше, но маршруты `DELETE` по-прежнему
 * закрывались правами `students.delete`, `teachers.delete` и `groups.delete`, и
 * у `study_records`, `deputy` и `academic_office` удаление проходило напрямую,
 * минуя заявку.
 *
 * Теперь эти маршруты требуют `trash.manage`, а три права выведены из
 * употребления: отвязаны от всех ролей и погашены. Записи не удаляются —
 * `User::hasPermission` смотрит на `active`, поэтому неактивное право не
 * проходит нигде, а обратный ход возвращает и активность, и прежние связи.
 *
 * Группы в корзину не кладутся: мягкого удаления у `groups` нет, а
 * `students.group_id` на них ссылается. Пометить группу на удаление нельзя,
 * удалить может только администратор. Заводить группы в корзину — отдельное
 * решение владельца.
 *
 * Идемпотентна.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const RETIRED = ['students.delete', 'teachers.delete', 'groups.delete'];

    /**
     * Кому права принадлежали до этой миграции — чтобы обратный ход вернул ровно
     * то, что было. Администратор не перечислен: он проходит мимо прав через
     * `Gate::before`, а полную выдачу получает от `RoleSeeder`.
     *
     * @var array<string, list<string>>
     */
    private const PREVIOUS_GRANTS = [
        'students.delete' => ['study_records', 'deputy', 'academic_office'],
        'teachers.delete' => ['deputy', 'academic_office'],
        'groups.delete' => ['study_records', 'deputy', 'academic_office'],
    ];

    public function up(): void
    {
        $ids = DB::table('permissions')->whereIn('code', self::RETIRED)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->update(['active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', self::RETIRED)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('permissions')->whereIn('id', $ids)->update(['active' => true, 'updated_at' => now()]);

        $adminId = DB::table('roles')->where('code', 'admin')->value('id');

        foreach (self::RETIRED as $code) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');

            if ($permissionId === null) {
                continue;
            }

            $roleIds = DB::table('roles')
                ->whereIn('code', self::PREVIOUS_GRANTS[$code] ?? [])
                ->pluck('id')
                ->all();

            if ($adminId !== null) {
                $roleIds[] = $adminId;
            }

            $existing = DB::table('permission_role')->where('permission_id', $permissionId)->pluck('role_id')->all();

            $rows = array_values(array_map(
                fn (int $roleId): array => ['role_id' => $roleId, 'permission_id' => $permissionId],
                array_diff(array_unique($roleIds), $existing),
            ));

            if ($rows !== []) {
                DB::table('permission_role')->insert($rows);
            }
        }
    }
};
