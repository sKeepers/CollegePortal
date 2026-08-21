<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал выдачи RFID-карт.
 *
 * До сих пор карта помнила только текущее состояние: кому выдана и когда. При
 * выдаче другому человеку прежний владелец затирался, и на вопрос «у кого эта
 * карта была в марте» ответить было нечем, а печатать журнал — не из чего.
 *
 * Теперь одна выдача — одна строка. Открытая выдача та, у которой нет даты
 * возврата. Поля в самой карте остаются, но становятся быстрым снимком «где
 * карта сейчас»: правда об истории живёт здесь.
 *
 * Карты выдаёт не только комендант — отдел кадров делает то же самое для
 * сотрудников. Право приходит миграцией, потому что `RoleSeeder` отрабатывает
 * при установке и больше никогда.
 */
return new class extends Migration
{
    private const HR_PERMISSIONS = ['rfid.cards.view', 'rfid.cards.manage'];

    public function up(): void
    {
        if (! Schema::hasTable('rfid_card_issues')) {
            Schema::create('rfid_card_issues', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('rfid_card_id')->constrained('rfid_cards')->cascadeOnDelete();
                $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
                $table->timestamp('issued_at');
                $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('returned_at')->nullable();
                $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                // Чем закончилась выдача: сдана, утеряна, испорчена, заменена,
                // человек выбыл. У открытой выдачи причины нет.
                $table->string('close_reason', 20)->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['rfid_card_id', 'issued_at']);
                $table->index(['person_id', 'issued_at']);
                $table->index('issued_at');
                $table->index('returned_at');
            });
        }

        if (! Schema::hasColumn('rfid_cards', 'uid_raw')) {
            Schema::table('rfid_cards', function (Blueprint $table): void {
                // То, что прислал считыватель, до приведения к общему виду.
                // Когда номер не сойдётся, сравнивать надо именно с этим.
                $table->string('uid_raw', 100)->nullable()->after('uid');
            });
        }

        $this->backfillIssues();
        $this->grantHr();
    }

    /**
     * Переносим то немногое, что карта помнила о себе сама.
     *
     * Одна строка на карту: у выданной — открытая, у прочих — закрытая с
     * причиной, выведенной из состояния. Точных дат в старой схеме не было,
     * поэтому берём то, что есть, а недостающее — из отметок времени строки.
     */
    private function backfillIssues(): void
    {
        if (DB::table('rfid_card_issues')->exists()) {
            return;
        }

        DB::table('rfid_cards')
            ->whereNotNull('person_id')
            ->orderBy('id')
            ->chunkById(200, function ($cards): void {
                $rows = [];

                foreach ($cards as $card) {
                    // Заблокированная карта остаётся на руках: проход по ней
                    // закрыт, но человек её не сдавал.
                    $open = in_array($card->status, ['issued', 'blocked'], true);

                    $rows[] = [
                        'rfid_card_id' => $card->id,
                        'person_id' => $card->person_id,
                        'issued_at' => $card->issued_at ?? $card->created_at,
                        'returned_at' => $open ? null : ($card->returned_at ?? $card->updated_at),
                        'close_reason' => $open ? null : match ($card->status) {
                            'lost' => 'lost',
                            'written_off' => 'damaged',
                            default => 'returned',
                        },
                        'note' => 'Перенесено из карточки при заведении журнала.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                if ($rows !== []) {
                    DB::table('rfid_card_issues')->insert($rows);
                }
            });
    }

    private function grantHr(): void
    {
        $roleId = DB::table('roles')->where('code', 'hr')->value('id');

        if ($roleId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')->whereIn('code', self::HR_PERMISSIONS)->pluck('id');

        foreach ($permissionIds as $permissionId) {
            // insertOrIgnore, а не проверка с последующей вставкой: на PostgreSQL
            // упавший INSERT отравил бы всю транзакцию миграции.
            DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rfid_card_issues');

        if (Schema::hasColumn('rfid_cards', 'uid_raw')) {
            Schema::table('rfid_cards', function (Blueprint $table): void {
                $table->dropColumn('uid_raw');
            });
        }

        $roleId = DB::table('roles')->where('code', 'hr')->value('id');

        if ($roleId !== null) {
            $permissionIds = DB::table('permissions')->whereIn('code', self::HR_PERMISSIONS)->pluck('id');
            DB::table('permission_role')->where('role_id', $roleId)->whereIn('permission_id', $permissionIds)->delete();
        }
    }
};
