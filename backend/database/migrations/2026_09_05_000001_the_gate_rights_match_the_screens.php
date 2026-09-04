<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Права проходной остаются у тех, кому проходную показывают.
 *
 * Замер 04.09.2026 (обход соседней области, воспроизведён мной поведением):
 * заместитель и две учебные части держали четыре права проходной, а шесть
 * экранов под ними их не пускали — заход уводит на «Доступ запрещён».
 * Проверено под ролью в режиме просмотра: `deputy` → `/access/gate`,
 * `/access/reports`, `/access/muster` — все три `/forbidden`. Сервер при этом
 * пускал: он смотрит только на право.
 *
 * Решение владельца 04.09.2026 в двух частях, и они пересекаются в одном праве:
 * **снять права проходной у учебных частей и заместителя**, но **«Кто сейчас в
 * здании» и «Отчёты по проходам» открыть директору и заместителю** — это лист
 * на случай эвакуации. Значит `gate.reports` у заместителя **остаётся**, а
 * уходят три остальных; у учебных частей уходят все четыре. Так эта миграция и
 * написана; если чтение неверно, вернуть проще всего `down()`.
 *
 * **Снятие права у роли, чей набор общий с другой, — молчаливо двойное.**
 * `RoleSeeder` кормит `deputy` и `academic_office` одной и той же
 * `academicEditorPermissions()`; вычеркнутое там уходит у обоих. Поэтому
 * `gate.reports` заместителю возвращён в сидере явной строкой, а здесь у него
 * не снимается вовсе. Это второй случай за сутки: утром так же собирался набор
 * куратора из набора преподавателя.
 *
 * Замер перед снятием: у трёх ролей три действующие учётные записи и **ноль**
 * следов в журнале по модулям проходной и пропусков (писали только `admin` и
 * `study_records`, а у последней этих прав нет). То есть снятие не отнимает
 * работу, которую кто-то делает.
 *
 * Идемпотентна: повторный запуск ничего не меняет.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> роль → права, которые ей больше не нужны */
    private const TAKE_AWAY = [
        'deputy' => ['gate.scan', 'gate.points.manage', 'digitalpasses.manage'],
        'study' => ['gate.scan', 'gate.points.manage', 'digitalpasses.manage', 'gate.reports'],
        'academic_office' => ['gate.scan', 'gate.points.manage', 'digitalpasses.manage', 'gate.reports'],
    ];

    public function up(): void
    {
        foreach (self::TAKE_AWAY as $role => $codes) {
            $roleId = DB::table('roles')->where('code', $role)->value('id');

            if ($roleId === null) {
                continue;
            }

            $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id')->all();

            if ($permissionIds === []) {
                continue;
            }

            DB::table('permission_role')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }
    }

    public function down(): void
    {
        foreach (self::TAKE_AWAY as $role => $codes) {
            $roleId = DB::table('roles')->where('code', $role)->value('id');

            if ($roleId === null) {
                continue;
            }

            foreach (DB::table('permissions')->whereIn('code', $codes)->pluck('id') as $permissionId) {
                // insertOrIgnore, а не проверка с последующей вставкой: на PostgreSQL
                // упавший INSERT отравил бы всю транзакцию миграции.
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }
};
