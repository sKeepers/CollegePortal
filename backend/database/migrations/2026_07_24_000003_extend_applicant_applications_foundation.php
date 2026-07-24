<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Расширяет legacy-таблицу заявлений foundation-полями без изменения старого CRUD.
     */
    public function up(): void
    {
        if (! Schema::hasTable('applicant_applications')) {
            return;
        }

        $this->addFoundationColumns();
        $this->backfillUuid();
        $this->addFoundationIndexes();
        $this->seedRequiredReferenceItems();

        if (Schema::hasTable('permissions')) {
            $this->seedPermissions();
        }
    }

    /**
     * Дополняет системный справочник минимальными статусами BACK-003 для уже существующих DEV-БД.
     */
    private function seedRequiredReferenceItems(): void
    {
        if (! Schema::hasTable('reference_catalogs') || ! Schema::hasTable('reference_items')) {
            return;
        }

        $catalogId = DB::table('reference_catalogs')
            ->where('code', 'admission_application_statuses')
            ->value('id');

        if (! $catalogId) {
            return;
        }

        foreach ([
            ['code' => 'draft', 'name' => 'Черновик', 'sort_order' => 10, 'metadata' => ['tone' => 'neutral', 'terminal' => false]],
            ['code' => 'registered', 'name' => 'Зарегистрировано', 'sort_order' => 15, 'metadata' => ['tone' => 'info', 'terminal' => false]],
            ['code' => 'withdrawn', 'name' => 'Отозвано', 'sort_order' => 120, 'metadata' => ['tone' => 'neutral', 'terminal' => true]],
        ] as $item) {
            DB::table('reference_items')->updateOrInsert(
                ['catalog_id' => $catalogId, 'code' => $item['code']],
                [
                    'name' => $item['name'],
                    'sort_order' => $item['sort_order'],
                    'is_active' => true,
                    'metadata' => json_encode([
                        'is_system' => true,
                        'module' => 'admissions',
                        ...$item['metadata'],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('applicant_applications')) {
            return;
        }

        $this->dropFoundationIndexes();

        Schema::table('applicant_applications', function (Blueprint $table): void {
            foreach (['updated_by', 'created_by', 'source_id', 'status_id', 'applicant_id'] as $column) {
                if (Schema::hasColumn('applicant_applications', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
        });

        Schema::table('applicant_applications', function (Blueprint $table): void {
            foreach (['metadata', 'registered_at', 'application_number', 'admission_year', 'uuid', 'archived_at'] as $column) {
                if (Schema::hasColumn('applicant_applications', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')
                ->whereIn('code', $this->permissionCodes())
                ->pluck('id');

            if (Schema::hasTable('permission_role')) {
                DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            }

            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }

    private function addFoundationColumns(): void
    {
        Schema::table('applicant_applications', function (Blueprint $table): void {
            if (! Schema::hasColumn('applicant_applications', 'uuid')) {
                $table->uuid('uuid')->nullable();
            }
            if (! Schema::hasColumn('applicant_applications', 'applicant_id')) {
                $table->foreignId('applicant_id')->nullable()->constrained('applicants')->nullOnDelete();
            }
            if (! Schema::hasColumn('applicant_applications', 'admission_year')) {
                $table->unsignedSmallInteger('admission_year')->nullable();
            }
            if (! Schema::hasColumn('applicant_applications', 'application_number')) {
                $table->string('application_number', 80)->nullable();
            }
            if (! Schema::hasColumn('applicant_applications', 'status_id')) {
                $table->foreignId('status_id')->nullable()->constrained('reference_items')->nullOnDelete();
            }
            if (! Schema::hasColumn('applicant_applications', 'source_id')) {
                $table->foreignId('source_id')->nullable()->constrained('reference_items')->nullOnDelete();
            }
            if (! Schema::hasColumn('applicant_applications', 'registered_at')) {
                $table->timestamp('registered_at')->nullable();
            }
            if (! Schema::hasColumn('applicant_applications', 'metadata')) {
                $table->json('metadata')->nullable();
            }
            if (! Schema::hasColumn('applicant_applications', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('applicant_applications', 'updated_by')) {
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('applicant_applications', 'archived_at')) {
                $table->timestamp('archived_at')->nullable();
            }
        });
    }

    private function backfillUuid(): void
    {
        DB::table('applicant_applications')
            ->whereNull('uuid')
            ->select('id')
            ->orderBy('id')
            ->cursor()
            ->each(function ($application): void {
                DB::table('applicant_applications')
                    ->where('id', $application->id)
                    ->update(['uuid' => (string) Str::uuid()]);
            });
    }

    private function addFoundationIndexes(): void
    {
        Schema::table('applicant_applications', function (Blueprint $table): void {
            $table->unique('uuid', 'applicant_applications_uuid_unique');
            $table->unique(['admission_year', 'application_number'], 'applicant_applications_year_number_unique');
            $table->index('admission_year', 'applicant_applications_admission_year_index');
            $table->index('application_number', 'applicant_applications_application_number_index');
            $table->index('registered_at', 'applicant_applications_registered_at_index');
            $table->index('archived_at', 'applicant_applications_archived_at_index');
        });
    }

    private function dropFoundationIndexes(): void
    {
        Schema::table('applicant_applications', function (Blueprint $table): void {
            foreach (['applicant_applications_uuid_unique', 'applicant_applications_year_number_unique'] as $index) {
                $table->dropUnique($index);
            }

            foreach ([
                'applicant_applications_admission_year_index',
                'applicant_applications_application_number_index',
                'applicant_applications_registered_at_index',
                'applicant_applications_archived_at_index',
            ] as $index) {
                $table->dropIndex($index);
            }
        });
    }

    /**
     * Идемпотентно добавляет permissions BACK-003 и назначает их текущим ролям.
     */
    private function seedPermissions(): void
    {
        $permissions = [
            ['code' => 'admissions.application.view', 'name' => 'Приемная комиссия: заявления просмотр', 'description' => 'Просмотр foundation-заявлений абитуриентов.'],
            ['code' => 'admissions.application.create', 'name' => 'Приемная комиссия: заявления создание', 'description' => 'Создание черновика заявления.'],
            ['code' => 'admissions.application.update', 'name' => 'Приемная комиссия: заявления изменение', 'description' => 'Изменение допустимых полей черновика заявления.'],
            ['code' => 'admissions.application.register', 'name' => 'Приемная комиссия: заявления регистрация', 'description' => 'Регистрация черновика заявления.'],
            ['code' => 'admissions.application.manage', 'name' => 'Приемная комиссия: заявления управление', 'description' => 'Будущее расширенное управление заявлениями.'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $permission['code']],
                [
                    'module' => 'Admissions',
                    ...$permission,
                    'system' => true,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $roleMap = [
            'admin' => $this->permissionCodes(),
            'admission' => $this->permissionCodes(),
            'director' => ['admissions.application.view'],
            'study' => ['admissions.application.view'],
            'academic_office' => ['admissions.application.view'],
        ];

        foreach ($roleMap as $roleCode => $codes) {
            $roleId = DB::table('roles')->where('code', $roleCode)->value('id');

            if (! $roleId || ! Schema::hasTable('permission_role')) {
                continue;
            }

            $permissionIds = DB::table('permissions')->whereIn('code', $codes)->pluck('id');

            foreach ($permissionIds as $permissionId) {
                DB::table('permission_role')->updateOrInsert([
                    'permission_id' => $permissionId,
                    'role_id' => $roleId,
                ]);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function permissionCodes(): array
    {
        return [
            'admissions.application.view',
            'admissions.application.create',
            'admissions.application.update',
            'admissions.application.register',
            'admissions.application.manage',
        ];
    }
};
