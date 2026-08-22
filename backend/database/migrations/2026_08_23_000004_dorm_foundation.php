<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Основание модуля общежития (`DORM-001`).
 *
 * Схема и разграничение прав взяты из разбора `docs/DORM_001_ANALYSIS.md`,
 * объём и спорные места закрыты решениями владельца от 16.08 и 22.08.2026.
 *
 * Два решения, которые видно прямо в схеме:
 *
 * **Проживающий — признак у студента, а место — отдельная запись.** Признак
 * отвечает на вопрос «живёт ли», заселение — «где и с какого числа». Иначе
 * каждое переселение правило бы карточку студента, а история переселений как
 * раз и нужна заместителю по воспитательной работе.
 *
 * **Провинности и социальный паспорт стоят особняком.** У них своё право, и
 * коменданту они не видны вовсе: это самые чувствительные данные во всём
 * портале, тяжелее оценок и медицинских справок.
 *
 * Роли и права приходят миграцией **и** остаются в `RoleSeeder`: сидер
 * выполняется при установке и больше никогда, а раздаёт права через `sync()` —
 * право, заведённое только миграцией, сотрётся на новой установке.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['module' => 'Dorm', 'code' => 'dorm.rooms.view', 'name' => 'Общежитие: комнаты', 'description' => 'Просмотр комнат и их занятости.'],
        ['module' => 'Dorm', 'code' => 'dorm.rooms.manage', 'name' => 'Общежитие: ведение комнат', 'description' => 'Заведение и изменение комнат.'],
        ['module' => 'Dorm', 'code' => 'dorm.placements.view', 'name' => 'Общежитие: заселения', 'description' => 'Просмотр заселений и переселений.'],
        ['module' => 'Dorm', 'code' => 'dorm.placements.manage', 'name' => 'Общежитие: ведение заселений', 'description' => 'Заселение, переселение и выселение.'],
        ['module' => 'Dorm', 'code' => 'dorm.payments.view', 'name' => 'Общежитие: оплата', 'description' => 'Просмотр отметок об оплате проживания.'],
        ['module' => 'Dorm', 'code' => 'dorm.payments.manage', 'name' => 'Общежитие: ведение оплаты', 'description' => 'Отметки об оплате проживания.'],
        ['module' => 'Dorm', 'code' => 'dorm.incidents.view', 'name' => 'Общежитие: происшествия', 'description' => 'Просмотр происшествий.'],
        ['module' => 'Dorm', 'code' => 'dorm.incidents.manage', 'name' => 'Общежитие: ведение происшествий', 'description' => 'Запись происшествий и принятых мер.'],
        ['module' => 'Dorm', 'code' => 'dorm.absences.view', 'name' => 'Общежитие: ночные отсутствия', 'description' => 'Просмотр ночных отсутствий и отлучек.'],
        ['module' => 'Dorm', 'code' => 'dorm.leaves.manage', 'name' => 'Общежитие: ведение отлучек', 'description' => 'Отлучка с ведома: домой, на соревнования, в больницу.'],
        ['module' => 'Dorm', 'code' => 'dorm.conduct.view', 'name' => 'Общежитие: провинности', 'description' => 'Просмотр записей о провинностях.'],
        ['module' => 'Dorm', 'code' => 'dorm.conduct.manage', 'name' => 'Общежитие: ведение провинностей', 'description' => 'Запись провинностей и работа с ними.'],
        ['module' => 'Dorm', 'code' => 'dorm.social.view', 'name' => 'Общежитие: социальный паспорт', 'description' => 'Просмотр социального паспорта и работы с трудными.'],
        ['module' => 'Dorm', 'code' => 'dorm.social.manage', 'name' => 'Общежитие: ведение социального паспорта', 'description' => 'Ведение социального паспорта и работы с трудными.'],
        ['module' => 'Dorm', 'code' => 'dorm.relocation.recommend', 'name' => 'Общежитие: рекомендация о переселении', 'description' => 'Рекомендация переселить проживающего.'],
    ];

    private const ROLES = [
        [
            'code' => 'dorm_warden',
            'name' => 'Комендант общежития',
            'description' => 'Места, заселение, оплата, происшествия, ночные отсутствия.',
            'permissions' => [
                'dashboard.view', 'view_own_data', 'reference.view', 'people.view', 'students.view',
                'dorm.rooms.view', 'dorm.rooms.manage',
                'dorm.placements.view', 'dorm.placements.manage',
                'dorm.payments.view', 'dorm.payments.manage',
                'dorm.incidents.view', 'dorm.incidents.manage',
                'dorm.absences.view', 'dorm.leaves.manage',
            ],
        ],
        [
            'code' => 'deputy_upbringing',
            'name' => 'Заместитель директора по воспитательной работе',
            'description' => 'Трудные, социальный паспорт, провинности, рекомендации о переселении.',
            'permissions' => [
                'dashboard.view', 'view_own_data', 'reference.view', 'people.view', 'students.view',
                'dorm.rooms.view', 'dorm.placements.view',
                'dorm.incidents.view', 'dorm.incidents.manage',
                'dorm.absences.view',
                'dorm.conduct.view', 'dorm.conduct.manage',
                'dorm.social.view', 'dorm.social.manage',
                'dorm.relocation.recommend',
            ],
        ],
    ];

    public function up(): void
    {
        $this->createTables();
        $this->addResidentFlag();
        $this->addPermissions();
        $this->grantToAdmin();
        $this->addRoles();
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('dorm_rooms')) {
            Schema::create('dorm_rooms', function (Blueprint $table): void {
                $table->id();
                // Комната принадлежит корпусу: общежитие заведено третьим
                // корпусом, и тогда отчёты по корпусам берут его сами.
                $table->foreignId('building_id')->constrained('buildings')->cascadeOnDelete();
                $table->string('number', 20);
                $table->unsignedSmallInteger('floor')->nullable();
                // Койки отдельной сущностью не заводим: занятость считается из
                // действующих заселений. Койко-место как объект нужно, только
                // если их различают по номерам, а этого никто не называл.
                $table->unsignedSmallInteger('capacity')->default(0);
                $table->string('kind', 20)->default('regular');
                $table->boolean('is_active')->default(true);
                $table->text('note')->nullable();
                $table->timestamps();

                $table->unique(['building_id', 'number']);
                $table->index('is_active');
            });
        }

        if (! Schema::hasTable('dorm_placements')) {
            Schema::create('dorm_placements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('dorm_room_id')->constrained('dorm_rooms')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->date('moved_in_at');
                $table->date('moved_out_at')->nullable();
                $table->string('basis', 255)->nullable();
                $table->text('note')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['dorm_room_id', 'moved_out_at']);
                $table->index(['student_id', 'moved_in_at']);
            });
        }

        if (! Schema::hasTable('dorm_payments')) {
            Schema::create('dorm_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                // «Оплачено по дату» — так владелец и считает: не помесячно, а
                // до какого числа человек закрыт.
                $table->date('paid_through');
                $table->decimal('amount', 10, 2)->nullable();
                $table->date('paid_at')->nullable();
                // Строка из 1С побеждает ручную отметку за тот же период, а
                // ручная помечается замещённой, но не удаляется. Иначе первый
                // же импорт молча сотрёт работу коменданта.
                $table->string('origin', 10)->default('manual');
                $table->string('external_id', 100)->nullable();
                $table->foreignId('superseded_by_id')->nullable()->constrained('dorm_payments')->nullOnDelete();
                $table->text('note')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['student_id', 'paid_through']);
                $table->index('origin');
            });
        }

        if (! Schema::hasTable('dorm_incidents')) {
            Schema::create('dorm_incidents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('building_id')->nullable()->constrained('buildings')->nullOnDelete();
                $table->foreignId('dorm_room_id')->nullable()->constrained('dorm_rooms')->nullOnDelete();
                $table->timestamp('happened_at');
                $table->string('summary', 255);
                $table->text('description')->nullable();
                $table->text('measures')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('happened_at');
            });
        }

        if (! Schema::hasTable('dorm_incident_participants')) {
            Schema::create('dorm_incident_participants', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('dorm_incident_id')->constrained('dorm_incidents')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->string('role', 20)->default('participant');
                $table->timestamps();

                $table->unique(['dorm_incident_id', 'student_id']);
            });
        }

        if (! Schema::hasTable('dorm_leaves')) {
            Schema::create('dorm_leaves', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                // Отлучка с ведома вычитается из расчёта ночей **до** того, как
                // отсутствие станет отсутствием. Без неё правило «вышел и не
                // вернулся» каждую пятницу собирало бы половину этажа.
                $table->date('starts_on');
                $table->date('ends_on');
                $table->string('reason', 255)->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['student_id', 'starts_on']);
                $table->index('ends_on');
            });
        }

        if (! Schema::hasTable('dorm_absences')) {
            Schema::create('dorm_absences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                // Ночь называем по дате её начала: ночь с 3-го на 4-е — это 3-е.
                $table->date('night_of');
                $table->timestamp('left_at')->nullable();
                $table->timestamp('returned_at')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->unique(['student_id', 'night_of']);
                $table->index('night_of');
            });
        }

        if (! Schema::hasTable('dorm_conduct_records')) {
            Schema::create('dorm_conduct_records', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->date('happened_on');
                $table->string('summary', 255);
                $table->text('description')->nullable();
                // Запись гаснет через год: остаётся в истории, но перестаёт
                // влиять на решения. Решение владельца от 22.08.2026.
                $table->date('expires_on')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['student_id', 'happened_on']);
                $table->index('expires_on');
            });
        }

        if (! Schema::hasTable('dorm_social_records')) {
            Schema::create('dorm_social_records', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->string('category', 40);
                $table->text('details')->nullable();
                $table->date('opened_on');
                $table->date('closed_on')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['student_id', 'category']);
                $table->index('closed_on');
            });
        }
    }

    private function addResidentFlag(): void
    {
        if (! Schema::hasColumn('students', 'is_resident')) {
            Schema::table('students', function (Blueprint $table): void {
                // Признак отвечает «живёт ли», а где именно — в заселении.
                $table->boolean('is_resident')->default(false)->after('status');
                $table->index('is_resident');
            });
        }
    }

    private function addPermissions(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            if (DB::table('permissions')->where('code', $permission['code'])->exists()) {
                continue;
            }

            DB::table('permissions')->insert($permission + [
                'system' => true,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Администратору новые права выдаёт миграция, а не только сидер.
     *
     * Сидер раздаёт администратору всё разом (`syncPermissions('admin', $all)`),
     * и связь роли с правом, которую даёт только он, до обновлённого портала не
     * доедет: `installer/update.sh` гоняет одни миграции. Это стережёт
     * `RightsArriveByMigrationTest`, и он же поймал пропуск здесь.
     */
    private function grantToAdmin(): void
    {
        $adminId = DB::table('roles')->where('code', 'admin')->value('id');

        if ($adminId === null) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('code', array_column(self::PERMISSIONS, 'code'))
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $adminId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    /**
     * Роли только добавляются.
     *
     * У роли, которая уже есть, набор прав не выравнивается: на боевом сервере
     * права могли править из интерфейса, и подгонять их под снимок нельзя.
     */
    private function addRoles(): void
    {
        foreach (self::ROLES as $role) {
            $roleId = DB::table('roles')->where('code', $role['code'])->value('id');

            if ($roleId !== null) {
                continue;
            }

            $roleId = DB::table('roles')->insertGetId([
                'code' => $role['code'],
                'name' => $role['name'],
                'description' => $role['description'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $permissionIds = DB::table('permissions')->whereIn('code', $role['permissions'])->pluck('id');

            foreach ($permissionIds as $permissionId) {
                // insertOrIgnore, а не проверка с последующей вставкой: на
                // PostgreSQL упавший INSERT отравил бы транзакцию миграции.
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (self::ROLES as $role) {
            $roleId = DB::table('roles')->where('code', $role['code'])->value('id');

            if ($roleId !== null) {
                DB::table('permission_role')->where('role_id', $roleId)->delete();
                DB::table('role_user')->where('role_id', $roleId)->delete();
                DB::table('roles')->where('id', $roleId)->delete();
            }
        }

        DB::table('permissions')->whereIn('code', array_column(self::PERMISSIONS, 'code'))->delete();

        if (Schema::hasColumn('students', 'is_resident')) {
            Schema::table('students', function (Blueprint $table): void {
                $table->dropColumn('is_resident');
            });
        }

        foreach ([
            'dorm_social_records',
            'dorm_conduct_records',
            'dorm_absences',
            'dorm_leaves',
            'dorm_incident_participants',
            'dorm_incidents',
            'dorm_payments',
            'dorm_placements',
            'dorm_rooms',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
