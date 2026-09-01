<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Директор видит общежитие.
 *
 * Владелец 01.09.2026 назвал троих, кому нужен раздел общежития: комендант
 * общежития, заместитель по воспитательной работе и директор. Замер того же дня:
 * права на общежитие были у администратора (15), заместителя по воспитательной
 * (10) и коменданта общежития (10), **а у директора — ни одного**. Убирать не
 * нужно ни у кого, директору нужно добавить.
 *
 * Даются только просмотровые, все семь. Это не сужение решения владельца, а
 * форма всего набора директора: в нём по каждому модулю стоят `*.view`, а
 * действия — у того, кто их выполняет. Заселяет комендант, воспитательную работу
 * ведёт заместитель; директор смотрит. Право `dorm.relocation.recommend` —
 * действие, и его здесь нет намеренно.
 *
 * Раздел «Общежитие» появляется в меню по праву `dorm.rooms.view`, а
 * «Воспитательная работа» — по `dorm.conduct.view` (`AppLayout.vue:149,152`);
 * оба в списке, поэтому директор увидит оба пункта, а не только адреса.
 *
 * Права заводить не нужно — все семь пришли миграцией `2026_08_23_000004`
 * вместе с самим общежитием. Здесь только выдача роли, и та же выдача добавлена
 * в `RoleSeeder::directorPermissions()`: миграция нужна установленному порталу
 * (сидер при обновлении не выполняется никогда), сидер — новой установке, где
 * `sync()` стёр бы выданное одной миграцией.
 *
 * Идемпотентна: повторный запуск ничего не добавляет.
 */
return new class extends Migration
{
    private const ROLE = 'director';

    private const CODES = [
        'dorm.rooms.view',
        'dorm.placements.view',
        'dorm.payments.view',
        'dorm.incidents.view',
        'dorm.absences.view',
        'dorm.conduct.view',
        'dorm.social.view',
    ];

    public function up(): void
    {
        $roleId = DB::table('roles')->where('code', self::ROLE)->value('id');

        if ($roleId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')->whereIn('code', self::CODES)->pluck('id');

        foreach ($permissionIds as $permissionId) {
            // insertOrIgnore, а не проверка с последующей вставкой: на PostgreSQL
            // упавший INSERT отравил бы всю транзакцию миграции.
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('code', self::ROLE)->value('id');

        if ($roleId === null) {
            return;
        }

        // Снимается только выдача роли. Сами права принадлежат общежитию и
        // держатся ещё тремя ролями — удалять их отсюда значило бы закрыть
        // раздел коменданту.
        $permissionIds = DB::table('permissions')->whereIn('code', self::CODES)->pluck('id');

        DB::table('permission_role')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }
};
