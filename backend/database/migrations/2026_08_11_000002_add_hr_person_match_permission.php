<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `HR-002`: право показать кадровику найденные совпадения.
 *
 * До него кадровик получал «Выберите существующую запись вручную», а выбирать
 * было не из чего: список в форме наполнялся реестром людей под `people.view`,
 * которого у кадров нет намеренно — в реестре ещё и студенты с абитуриентами.
 *
 * Право отдельное, а не спрятанное внутри `hr.employees.create`: кадры получают
 * узкий взгляд в общий реестр, и эта уступка должна быть видна в матрице
 * разрешений и отзываема отдельно.
 *
 * Заводится миграцией, а не только сидером: сидер выполняется при установке и
 * больше никогда. Идемпотентна.
 */
return new class extends Migration
{
    private const CODE = 'hr.people.match';

    /** Кадры и всё. Администратор проходит мимо прав через `Gate::before`. */
    private const ROLES = ['hr'];

    public function up(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            $permissionId = DB::table('permissions')->insertGetId([
                'code' => self::CODE,
                'name' => 'Кадры: поиск человека по совпадениям',
                'module' => 'HR',
                'description' => 'Показ найденных совпадений при заведении сотрудника, без доступа к реестру людей.',
                'system' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $existing = DB::table('permission_role')->where('permission_id', $permissionId)->pluck('role_id')->all();

        $rows = DB::table('roles')
            ->whereIn('code', self::ROLES)
            ->pluck('id')
            ->reject(fn (int $roleId): bool => in_array($roleId, $existing, true))
            ->map(fn (int $roleId): array => ['role_id' => $roleId, 'permission_id' => $permissionId])
            ->all();

        if ($rows !== []) {
            DB::table('permission_role')->insert($rows);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');

        if ($permissionId === null) {
            return;
        }

        DB::table('permission_role')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
