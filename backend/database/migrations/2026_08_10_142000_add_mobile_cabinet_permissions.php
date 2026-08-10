<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Права мобильных кабинетов — по заявке чата «Мобильные кабинеты» от
 * 10.08.2026.
 *
 * Право решает только видимость раздела. Чьи данные видно, решает сам
 * эндпоинт: преподаватель находится по `teachers.user_id`, куратор — по
 * `groups.curator_id`, и подстановка чужого идентификатора там не проходит.
 *
 * `mobile.admin.view` не выдаётся никому: администратор проходит мимо прав
 * через `Gate::before`, а на установке с нуля полный набор ему даёт
 * `RoleSeeder`.
 *
 * Заводится миграцией, а не только сидером: сидер выполняется при установке и
 * больше никогда, и ровно на этом уже потерялось `gate.points.manage`.
 *
 * Идемпотентна.
 */
return new class extends Migration
{
    /** @var list<array{code:string,name:string,description:string}> */
    private const PERMISSIONS = [
        [
            'code' => 'mobile.teacher.view',
            'name' => 'Мобильный кабинет преподавателя',
            'description' => 'Доступ к мобильному кабинету преподавателя.',
        ],
        [
            'code' => 'mobile.curator.view',
            'name' => 'Мобильный кабинет куратора',
            'description' => 'Доступ к мобильному кабинету куратора группы.',
        ],
        [
            'code' => 'mobile.admin.view',
            'name' => 'Мобильный кабинет администратора',
            'description' => 'Доступ к мобильному кабинету администратора.',
        ],
    ];

    /** @var array<string, list<string>> */
    private const GRANTS = [
        'mobile.teacher.view' => ['teacher', 'curator'],
        'mobile.curator.view' => ['curator'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $permission) {
            if (DB::table('permissions')->where('code', $permission['code'])->exists()) {
                continue;
            }

            DB::table('permissions')->insert($permission + [
                'module' => 'Mobile',
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

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('code', array_column(self::PERMISSIONS, 'code'))
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
