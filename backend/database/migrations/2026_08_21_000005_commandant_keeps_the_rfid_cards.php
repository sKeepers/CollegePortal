<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Учёт RFID-карточек и роль коменданта.
 *
 * Пропуск в портале до сих пор был только один — динамический QR на телефоне.
 * Физические карты живут отдельной жизнью: их выдают под роспись, теряют,
 * блокируют и принимают обратно, и ведёт их комендант. Тетрадь эту переносим в
 * портал: карта, её состояние и то, у кого она сейчас на руках.
 *
 * Карта привязывается к **человеку**, а не к карточке студента или сотрудника:
 * человек может быть и тем и другим сразу, а карта у него одна.
 *
 * Роль и права приходят миграцией, а не только сидером: сидер выполняется при
 * установке и больше никогда, и на уже стоящем портале роль иначе не появится.
 * Миграция только добавляет и ничего не выравнивает.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['module' => 'Identity', 'code' => 'rfid.cards.view', 'name' => 'RFID-карты: просмотр', 'description' => 'Просмотр списка карт и того, у кого они на руках.'],
        ['module' => 'Identity', 'code' => 'rfid.cards.manage', 'name' => 'RFID-карты: ведение', 'description' => 'Заведение карт, выдача, приём, блокировка и списание.'],
    ];

    private const ROLE = ['code' => 'commandant', 'name' => 'Комендант', 'description' => 'Учёт RFID-карт: выдача, приём, блокировка.'];

    /** Что получает комендант. Своё собственное — чтобы видеть свой же пропуск. */
    private const ROLE_PERMISSIONS = ['dashboard.view', 'view_own_data', 'people.view', 'rfid.cards.view', 'rfid.cards.manage'];

    public function up(): void
    {
        if (! Schema::hasTable('rfid_cards')) {
            Schema::create('rfid_cards', function (Blueprint $table) {
                $table->id();
                // Номер, который читает считыватель. Уникален: две карты с одним
                // номером неразличимы на проходной.
                $table->string('uid', 100)->unique();
                // Инвентарная подпись — то, что написано на самой карте.
                $table->string('label', 100)->nullable();
                $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
                $table->string('status', 20)->default('stock');
                $table->date('issued_at')->nullable();
                $table->date('returned_at')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index('status');
                $table->index('person_id');
            });
        }

        foreach (self::PERMISSIONS as $permission) {
            $exists = DB::table('permissions')->where('code', $permission['code'])->exists();

            if (! $exists) {
                DB::table('permissions')->insert($permission + [
                    'system' => true,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $roleId = DB::table('roles')->where('code', self::ROLE['code'])->value('id');

        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId(self::ROLE + ['created_at' => now(), 'updated_at' => now()]);
        }

        $permissionIds = DB::table('permissions')->whereIn('code', self::ROLE_PERMISSIONS)->pluck('id');

        foreach ($permissionIds as $permissionId) {
            // insertOrIgnore, а не проверка с последующей вставкой: на PostgreSQL
            // упавший INSERT отравил бы всю транзакцию миграции.
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rfid_cards');

        $roleId = DB::table('roles')->where('code', self::ROLE['code'])->value('id');

        if ($roleId !== null) {
            DB::table('permission_role')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }

        DB::table('permissions')->whereIn('code', array_column(self::PERMISSIONS, 'code'))->delete();
    }
};
