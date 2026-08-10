<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `ARCH-001`, шаг 3: вывести из употребления legacy-права-«зонтики».
 *
 * Зонтик открывал роли целую группу маршрутов, не требуя ни одного конкретного
 * права. Право теперь объявлено у маршрута, ни один маршрут зонтик не
 * проверяет — держать его у ролей значит держать выданный и никому не видимый
 * доступ.
 *
 * Записи прав не удаляются. Они помечаются неактивными: `User::hasPermission`
 * смотрит на `active`, поэтому неактивное право не проходит нигде, а обратный
 * ход возвращает и активность, и прежние связи. Удалить записи можно будет
 * отдельной задачей, когда портал проживёт релиз без них.
 *
 * Идемпотентна.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const UMBRELLAS = [
        'manage_users',
        'manage_dictionaries',
        'manage_schedule',
        'manage_journal',
        'view_reports',
    ];

    /**
     * Кому зонтики принадлежали до этой миграции — чтобы обратный ход вернул
     * ровно то, что было, а не «всем подряд».
     *
     * Администратор не перечислен: он проходит мимо прав через `Gate::before`,
     * а свою полную выдачу получает от `RoleSeeder`.
     *
     * @var array<string, list<string>>
     */
    private const PREVIOUS_GRANTS = [
        'manage_dictionaries' => ['study', 'deputy', 'academic_office'],
        'manage_schedule' => ['study', 'deputy', 'academic_office'],
        'manage_journal' => ['study_records', 'teacher', 'curator', 'deputy', 'academic_office'],
        'view_reports' => ['director', 'study', 'study_records', 'admission', 'security', 'deputy', 'academic_office'],
    ];

    public function up(): void
    {
        $ids = DB::table('permissions')->whereIn('code', self::UMBRELLAS)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->update(['active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', self::UMBRELLAS)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('permissions')->whereIn('id', $ids)->update(['active' => true, 'updated_at' => now()]);

        $adminId = DB::table('roles')->where('code', 'admin')->value('id');

        foreach (self::UMBRELLAS as $code) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');

            if ($permissionId === null) {
                continue;
            }

            $roleCodes = self::PREVIOUS_GRANTS[$code] ?? [];
            $roleIds = DB::table('roles')->whereIn('code', $roleCodes)->pluck('id')->all();

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
