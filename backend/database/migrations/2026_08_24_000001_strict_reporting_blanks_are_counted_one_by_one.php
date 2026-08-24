<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Учёт бланков строгой отчётности: диплом, приложение, дубликат.
 *
 * До сих пор серия и номер бланка лежали прямо в дипломе, и этого хватало,
 * пока никто не спрашивал про остаток. Бланк гознака — **материальная
 * ценность**, за которую отчитываются поштучно: партия приходит с накладной и
 * диапазоном номеров, номер закрепляется за выпускником, испорченный при печати
 * бланк списывается актом.
 *
 * Главное в этом учёте — **не «выдан», а «испорчен» и «списан»**. Выданный
 * бланк виден в дипломе и без книги. Испорченный не виден нигде, и если он
 * просто исчезнет, на вопрос «где номер такой-то» ответить будет нечем.
 * Поэтому:
 *
 * - **строки не удаляются никогда** — ни бланк, ни партия, ни движение. Запрет
 *   стоит в моделях (`DiplomaBlank`, `DiplomaBlankBatch`, `DiplomaBlankEvent`),
 *   маршрута на удаление нет вовсе;
 * - испорченный бланк остаётся с номером, причиной и датой, а списание только
 *   добавляет к нему номер акта;
 * - каждое движение пишется отдельной строкой: кто, когда, из какого состояния
 *   в какое и почему.
 *
 * Три таблицы, а не одна: партия отвечает на «откуда взялись эти номера»,
 * бланк — на «где он сейчас», движение — на «что с ним было». Свести их в одну
 * значит потерять историю при первой же правке состояния.
 *
 * Права и их выдача идут миграцией, а не только сидером: сидер выполняется при
 * установке и больше никогда, и на уже стоящем портале права иначе не появятся.
 * Миграция только добавляет и ничего не выравнивает.
 *
 * **Кто именно получает партию и кто подписывает списание — владелец ещё не
 * ответил** (вопрос 3 в `docs/DIPLOMA_PRINTING.md`). До ответа право ведения
 * выдано тем же ролям, что уже ведут выпуск: учебной части 2 и заместителю
 * директора. Это заведомо не шире, чем у них уже есть.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        [
            'module' => 'Graduation',
            'code' => 'diploma.blanks.view',
            'name' => 'Бланки дипломов: просмотр',
            'description' => 'Просмотр остатка бланков, их состояний и движения.',
        ],
        [
            'module' => 'Graduation',
            'code' => 'diploma.blanks.manage',
            'name' => 'Бланки дипломов: ведение',
            'description' => 'Приход партии, закрепление за выпускником, выдача, отметка о порче и списание.',
        ],
    ];

    /**
     * Кто уже ведёт выпуск, тот ведёт и бланки.
     *
     * **Администратор перечислен явно, хотя и проходит мимо прав через
     * `Gate::before`.** Списки ролей здесь обязаны совпадать с `RoleSeeder` до
     * строки: установка выполняет сидер, обновление — одни миграции, и любое
     * расхождение означает, что обновлённый портал отличается от
     * свежепоставленного. Закреплено `RightsArriveByMigrationTest`, который
     * это и поймал.
     */
    private const VIEW_ROLES = ['admin', 'director', 'deputy', 'academic_office', 'study', 'study_records'];

    private const MANAGE_ROLES = ['admin', 'deputy', 'academic_office', 'study_records'];

    public function up(): void
    {
        Schema::create('diploma_blank_batches', function (Blueprint $table): void {
            $table->id();
            // Вид бланка: у диплома, приложения и дубликата своя нумерация, и
            // серии у них разные. Один справочник на всех был бы враньём.
            $table->string('kind', 30)->index();
            $table->string('series', 50);
            // Номера хранятся строкой, а не числом: у гознака они с ведущими
            // нулями, и `0000123` — это не `123`.
            $table->string('number_from', 50);
            $table->string('number_to', 50);
            $table->unsignedInteger('quantity');
            $table->date('received_at');
            $table->string('supplier', 255)->nullable();
            $table->string('invoice_number', 100)->nullable();
            $table->foreignId('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['kind', 'series']);
        });

        Schema::create('diploma_blanks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('diploma_blank_batch_id')->constrained()->restrictOnDelete();
            $table->string('kind', 30);
            $table->string('series', 50);
            $table->string('number', 50);
            $table->string('status', 20)->default('stock')->index();
            $table->foreignId('graduate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('diploma_id')->nullable()->constrained()->nullOnDelete();
            $table->date('assigned_at')->nullable();
            $table->date('issued_at')->nullable();
            $table->date('spoiled_at')->nullable();
            $table->date('written_off_at')->nullable();
            // Номер акта списания. Без него списание — это просто пропавший бланк.
            $table->string('write_off_act', 100)->nullable();
            $table->text('reason')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // Два бланка одного вида с одной серией и номером неразличимы, и
            // остаток по ним посчитать нельзя.
            $table->unique(['kind', 'series', 'number']);
            $table->index(['kind', 'status']);
            $table->index('graduate_id');
        });

        Schema::create('diploma_blank_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('diploma_blank_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('graduate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('act_number', 100)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('happened_at');
            $table->timestamps();

            $table->index(['diploma_blank_id', 'happened_at']);
        });

        foreach (self::PERMISSIONS as $permission) {
            if (! DB::table('permissions')->where('code', $permission['code'])->exists()) {
                DB::table('permissions')->insert($permission + [
                    'system' => true,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->grant('diploma.blanks.view', self::VIEW_ROLES);
        $this->grant('diploma.blanks.manage', self::MANAGE_ROLES);
    }

    public function down(): void
    {
        Schema::dropIfExists('diploma_blank_events');
        Schema::dropIfExists('diploma_blanks');
        Schema::dropIfExists('diploma_blank_batches');

        foreach (self::PERMISSIONS as $permission) {
            $id = DB::table('permissions')->where('code', $permission['code'])->value('id');

            if ($id === null) {
                continue;
            }

            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }

    /** @param  list<string>  $roles */
    private function grant(string $code, array $roles): void
    {
        $permissionId = DB::table('permissions')->where('code', $code)->value('id');

        if ($permissionId === null) {
            return;
        }

        $rows = DB::table('roles')
            ->whereIn('code', $roles)
            ->pluck('id')
            ->map(fn (int $roleId): array => ['role_id' => $roleId, 'permission_id' => $permissionId])
            ->all();

        if ($rows !== []) {
            // insertOrIgnore, а не проверка с последующей вставкой: на PostgreSQL
            // упавший INSERT отравил бы всю транзакцию миграции.
            DB::table('permission_role')->insertOrIgnore($rows);
        }
    }
};
