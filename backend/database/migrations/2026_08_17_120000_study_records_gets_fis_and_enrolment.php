<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Решение владельца 17.08.2026: учебная часть ведёт справочники ФИС и зачисляет.
 *
 * Найдено от жалобы: человек вошёл под ролью, которая «должна работать со
 * студентами», и не нашёл раздела ФИС. Замер показал больше: у роли не было
 * **ни одного** права ни по ФИС, ни по приёму. То есть контингент она ведёт, а
 * данные для него — специальности из ФИС и студентов из заявлений — заводить не
 * может, и за каждой загрузкой идёт к администратору.
 *
 * Что даётся и чего не даётся:
 *
 * * **справочники ФИС применять можно** — это прямая формулировка владельца;
 * * **пакеты в государственную систему формировать и отправлять нельзя** — это
 *   осталось у администратора и приёмной комиссии;
 * * **приём только на чтение плюс зачисление** — заводить и править заявления,
 *   принимать и проверять документы по-прежнему дело приёмной комиссии.
 *
 * Заводится и новое право `reference.programs.view`. Оно открывает **разделы**
 * «Специальности» и «Образовательные программы», а не данные: читать их
 * разрешает `reference.view`, выданное всем ролям, и те же сведения портал
 * отдаёт публичной странице вообще без входа. До сих пор меню и маршрутизатор
 * фронтенда были закрыты правом на **правку**, из-за чего получалось «данные
 * открыты, а раздела нет», и причина не была видна ниоткуда.
 *
 * **Миграция только добавляет.** Права уже существующей роли она не выравнивает:
 * на боевом сервере их могли править из интерфейса, и «привести к снимку»
 * значило бы молча отобрать доступ у живых людей.
 */
return new class extends Migration
{
    /** Новое право и его описание для каталога. */
    private const NEW_PERMISSION = [
        'code' => 'reference.programs.view',
        'name' => 'Специальности и программы: раздел',
        'module' => 'System',
        'description' => 'Показывать разделы «Специальности» и «Образовательные программы» тем, кто ведёт контингент и выпуск.',
    ];

    /** Что каким ролям добавить. Администратор проходит мимо прав через `Gate::before`. */
    private const GRANTS = [
        'study_records' => [
            'fis.view', 'fis.outbound.view', 'fis.settings.manage',
            'admissions.view', 'admissions.applicant.view', 'admissions.application.view',
            'admissions.choice.view', 'admissions.document.view', 'admissions.documents.view',
            'admissions.reference.view', 'admissions.edit',
            'reference.programs.view',
        ],
        'study' => ['reference.programs.view'],
    ];

    public function up(): void
    {
        $this->ensureNewPermission();

        foreach (self::GRANTS as $roleCode => $codes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');

            if ($roleId === null) {
                continue;
            }

            $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id');

            // Уже выданное не дублируем: у таблицы нет уникального ключа, а
            // повторная строка сделала бы выдачу прав неотличимой от ошибки.
            $existing = DB::table('permission_role')
                ->where('role_id', $roleId)
                ->pluck('permission_id')
                ->all();

            $rows = $permissionIds
                ->reject(fn (int $permissionId): bool => in_array($permissionId, $existing, true))
                ->map(fn (int $permissionId): array => ['role_id' => $roleId, 'permission_id' => $permissionId])
                ->all();

            if ($rows !== []) {
                DB::table('permission_role')->insert($rows);
            }
        }
    }

    /**
     * Откат снимает **только то, что миграция завела**: новое право целиком и
     * выдачи по списку. Прав, которые роль имела до неё, он не касается.
     */
    public function down(): void
    {
        foreach (self::GRANTS as $roleCode => $codes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');

            if ($roleId === null) {
                continue;
            }

            $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id');

            DB::table('permission_role')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }

        $permissionId = DB::table('permissions')->where('code', self::NEW_PERMISSION['code'])->value('id');

        if ($permissionId !== null) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }

    private function ensureNewPermission(): void
    {
        if (DB::table('permissions')->where('code', self::NEW_PERMISSION['code'])->exists()) {
            return;
        }

        DB::table('permissions')->insert(self::NEW_PERMISSION + [
            'system' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
