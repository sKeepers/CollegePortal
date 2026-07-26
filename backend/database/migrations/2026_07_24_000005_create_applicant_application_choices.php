<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicant_application_choices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('applicant_applications')->cascadeOnDelete();
            $table->unsignedSmallInteger('priority');
            $table->foreignId('specialty_id')->nullable()->constrained('specialties')->nullOnDelete();
            $table->foreignId('education_program_id')->constrained('education_programs')->restrictOnDelete();
            $table->foreignId('education_form_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->foreignId('funding_form_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->foreignId('base_education_type_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->foreignId('quota_type_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->foreignId('status_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'priority'], 'application_choices_application_priority_index');
            $table->index(['application_id', 'education_program_id'], 'application_choices_application_program_index');
            $table->index(['application_id', 'archived_at'], 'application_choices_application_archived_index');
            $table->index('status_id', 'application_choices_status_index');
        });

        if (Schema::hasTable('permissions')) {
            $this->seedPermissions();
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')
                ->whereIn('code', $this->permissionCodes())
                ->pluck('id');

            if (Schema::hasTable('permission_role')) {
                DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            }

            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('applicant_application_choices');
    }

    private function seedPermissions(): void
    {
        $permissions = [
            ['code' => 'admissions.choice.view', 'name' => 'Приемная комиссия: выборы программ просмотр', 'description' => 'Просмотр выбранных образовательных программ заявления.'],
            ['code' => 'admissions.choice.create', 'name' => 'Приемная комиссия: выборы программ создание', 'description' => 'Добавление выбранной образовательной программы к заявлению.'],
            ['code' => 'admissions.choice.update', 'name' => 'Приемная комиссия: выборы программ изменение', 'description' => 'Изменение приоритета, формы, финансирования, основания и статуса выбора.'],
            ['code' => 'admissions.choice.delete', 'name' => 'Приемная комиссия: выборы программ удаление', 'description' => 'Архивирование выбранной образовательной программы заявления.'],
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
            'director' => ['admissions.choice.view'],
            'study' => ['admissions.choice.view'],
            'academic_office' => ['admissions.choice.view'],
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

    private function permissionCodes(): array
    {
        return [
            'admissions.choice.view',
            'admissions.choice.create',
            'admissions.choice.update',
            'admissions.choice.delete',
        ];
    }
};
