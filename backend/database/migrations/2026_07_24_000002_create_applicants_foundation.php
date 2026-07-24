<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Создает foundation абитуриента и безопасно дополняет существующую Person-модель UUID.
     */
    public function up(): void
    {
        if (Schema::hasTable('people') && ! Schema::hasColumn('people', 'uuid')) {
            Schema::table('people', function (Blueprint $table): void {
                $table->uuid('uuid')->nullable()->after('id');
            });

            DB::table('people')->whereNull('uuid')->select('id')->orderBy('id')->cursor()->each(function ($person): void {
                DB::table('people')->where('id', $person->id)->update(['uuid' => (string) Str::uuid()]);
            });

            Schema::table('people', function (Blueprint $table): void {
                $table->unique('uuid');
            });
        }

        if (! Schema::hasTable('applicants')) {
            Schema::create('applicants', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
                $table->foreignId('source_id')->nullable()->constrained('reference_items')->nullOnDelete();
                $table->foreignId('status_id')->constrained('reference_items')->restrictOnDelete();
                $table->timestamp('first_contact_at')->nullable();
                $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();

                $table->index('person_id');
                $table->index('source_id');
                $table->index('status_id');
                $table->index('responsible_user_id');
                $table->index('archived_at');
                $table->index(['status_id', 'archived_at']);
            });
        }

        if (Schema::hasTable('permissions')) {
            $this->seedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('applicants');

        if (Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')
                ->whereIn('code', ['admissions.applicant.view', 'admissions.applicant.manage'])
                ->pluck('id');

            if (Schema::hasTable('permission_role')) {
                DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            }

            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        if (Schema::hasTable('people') && Schema::hasColumn('people', 'uuid')) {
            Schema::table('people', function (Blueprint $table): void {
                $table->dropUnique(['uuid']);
                $table->dropColumn('uuid');
            });
        }
    }

    /**
     * Идемпотентно добавляет permissions BACK-002 и назначает их существующим ролям.
     */
    private function seedPermissions(): void
    {
        $permissions = [
            [
                'module' => 'Admissions',
                'code' => 'admissions.applicant.view',
                'name' => 'Приемная комиссия: абитуриенты просмотр',
                'description' => 'Просмотр foundation-профилей абитуриентов.',
            ],
            [
                'module' => 'Admissions',
                'code' => 'admissions.applicant.manage',
                'name' => 'Приемная комиссия: абитуриенты управление',
                'description' => 'Будущее создание, связывание и архивирование профилей абитуриентов.',
            ],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $permission['code']],
                [
                    ...$permission,
                    'system' => true,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $roleMap = [
            'admin' => ['admissions.applicant.view', 'admissions.applicant.manage'],
            'admission' => ['admissions.applicant.view', 'admissions.applicant.manage'],
            'director' => ['admissions.applicant.view'],
            'study' => ['admissions.applicant.view'],
            'academic_office' => ['admissions.applicant.view'],
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
};
