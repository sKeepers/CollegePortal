<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создает структурированные документы Admissions Foundation без изменения legacy registry.
     */
    public function up(): void
    {
        if (Schema::hasTable('people') && ! Schema::hasColumn('people', 'snils_hash')) {
            Schema::table('people', function (Blueprint $table): void {
                $table->string('snils_hash', 64)->nullable()->after('snils');
                $table->index('snils_hash', 'people_snils_hash_index');
            });
        }

        Schema::create('admission_identity_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('applicant_id')->constrained('applicants')->restrictOnDelete();
            $table->foreignId('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->string('series', 20)->nullable();
            $table->string('number', 100)->nullable();
            $table->string('number_hash', 64)->nullable();
            $table->date('issue_date')->nullable();
            $table->string('issued_by', 1000)->nullable();
            $table->string('subdivision_code', 32)->nullable();
            $table->foreignId('release_country_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->string('release_country_name', 255)->nullable();
            $table->string('release_place', 1000)->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('verification_status', 40)->default('received');
            $table->text('verification_comment')->nullable();
            $table->string('fis_uid', 200)->nullable();
            $table->unsignedInteger('fis_identity_document_type_id')->nullable();
            $table->unsignedInteger('fis_nationality_type_id')->nullable();
            $table->unsignedInteger('fis_release_country_id')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['applicant_id', 'archived_at'], 'admission_identity_applicant_archived_index');
            $table->index(['person_id', 'archived_at'], 'admission_identity_person_archived_index');
            $table->index(['applicant_id', 'is_primary', 'archived_at'], 'admission_identity_primary_index');
            $table->index('verification_status', 'admission_identity_verification_status_index');
            $table->index('number_hash', 'admission_identity_number_hash_index');
            $table->index('fis_uid', 'admission_identity_fis_uid_index');
        });

        Schema::create('admission_education_documents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('applicant_id')->constrained('applicants')->restrictOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->string('series', 20)->nullable();
            $table->string('number', 100)->nullable();
            $table->string('number_hash', 64)->nullable();
            $table->date('issue_date')->nullable();
            $table->string('document_organization', 1000)->nullable();
            $table->foreignId('country_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->string('country_name', 255)->nullable();
            $table->foreignId('education_level_id')->nullable()->constrained('reference_items')->nullOnDelete();
            $table->unsignedSmallInteger('graduation_year')->nullable();
            $table->boolean('is_original')->default(false);
            $table->date('original_received_at')->nullable();
            $table->decimal('average_score', 5, 2)->nullable();
            $table->string('average_score_scale', 32)->nullable();
            $table->boolean('has_attachment')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->string('verification_status', 40)->default('received');
            $table->text('verification_comment')->nullable();
            $table->string('fis_uid', 200)->nullable();
            $table->unsignedInteger('fis_document_type_id')->nullable();
            $table->unsignedInteger('fis_country_id')->nullable();
            $table->unsignedInteger('fis_region_id')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['applicant_id', 'archived_at'], 'admission_education_applicant_archived_index');
            $table->index(['applicant_id', 'is_primary', 'archived_at'], 'admission_education_primary_index');
            $table->index('verification_status', 'admission_education_verification_status_index');
            $table->index('number_hash', 'admission_education_number_hash_index');
            $table->index('fis_uid', 'admission_education_fis_uid_index');
        });

        Schema::create('admission_document_files', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('applicant_id')->constrained('applicants')->restrictOnDelete();
            $table->foreignId('application_id')->nullable()->constrained('applicant_applications')->nullOnDelete();
            $table->foreignId('identity_document_id')->nullable()->constrained('admission_identity_documents')->cascadeOnDelete();
            $table->foreignId('education_document_id')->nullable()->constrained('admission_education_documents')->cascadeOnDelete();
            $table->string('category', 40)->default('other');
            $table->string('original_name');
            $table->string('generated_name');
            $table->string('storage_path');
            $table->string('mime_type', 120);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['applicant_id', 'archived_at'], 'admission_document_files_applicant_archived_index');
            $table->index(['identity_document_id', 'archived_at'], 'admission_document_files_identity_archived_index');
            $table->index(['education_document_id', 'archived_at'], 'admission_document_files_education_archived_index');
            $table->index('sha256', 'admission_document_files_sha256_index');
        });

        $this->seedReferenceData();
        $this->seedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_document_files');
        Schema::dropIfExists('admission_education_documents');
        Schema::dropIfExists('admission_identity_documents');

        if (Schema::hasTable('people') && Schema::hasColumn('people', 'snils_hash')) {
            Schema::table('people', function (Blueprint $table): void {
                $table->dropIndex('people_snils_hash_index');
                $table->dropColumn('snils_hash');
            });
        }

        if (Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')->whereIn('code', $this->permissionCodes())->pluck('id');

            if (Schema::hasTable('permission_role')) {
                DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            }

            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }

    private function seedReferenceData(): void
    {
        if (! Schema::hasTable('reference_catalogs') || ! Schema::hasTable('reference_items')) {
            return;
        }

        foreach ($this->catalogs() as $catalogData) {
            $items = $catalogData['items'];
            unset($catalogData['items']);

            DB::table('reference_catalogs')->updateOrInsert(
                ['code' => $catalogData['code']],
                [
                    'name' => $catalogData['name'],
                    'description' => $catalogData['description'],
                    'is_system' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );

            $catalogId = DB::table('reference_catalogs')->where('code', $catalogData['code'])->value('id');

            foreach ($items as $index => $item) {
                DB::table('reference_items')->updateOrInsert(
                    ['catalog_id' => $catalogId, 'code' => $item['code']],
                    [
                        'name' => $item['name'],
                        'sort_order' => ($index + 1) * 10,
                        'is_active' => true,
                        'metadata' => json_encode([
                            'is_system' => true,
                            'module' => 'admissions',
                            ...($item['metadata'] ?? []),
                        ], JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
    }

    private function seedPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissions = [
            ['code' => 'admissions.document.view', 'name' => 'Приемная комиссия: документы просмотр', 'description' => 'Просмотр структурированных foundation-документов абитуриента.'],
            ['code' => 'admissions.document.create', 'name' => 'Приемная комиссия: документы создание', 'description' => 'Создание структурированных foundation-документов.'],
            ['code' => 'admissions.document.update', 'name' => 'Приемная комиссия: документы изменение', 'description' => 'Изменение реквизитов структурированных foundation-документов.'],
            ['code' => 'admissions.document.delete', 'name' => 'Приемная комиссия: документы архивирование', 'description' => 'Архивирование foundation-документов и файлов без физического удаления.'],
            ['code' => 'admissions.document.verify', 'name' => 'Приемная комиссия: документы проверка', 'description' => 'Изменение статуса проверки foundation-документов.'],
            ['code' => 'admissions.document.download_sensitive', 'name' => 'Приемная комиссия: скачать чувствительные документы', 'description' => 'Скачивание файлов документов из private storage.'],
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
            'director' => ['admissions.document.view', 'admissions.document.download_sensitive'],
            'study' => ['admissions.document.view'],
            'academic_office' => ['admissions.document.view'],
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
            'admissions.document.view',
            'admissions.document.create',
            'admissions.document.update',
            'admissions.document.delete',
            'admissions.document.verify',
            'admissions.document.download_sensitive',
        ];
    }

    private function catalogs(): array
    {
        return [
            [
                'code' => 'admission_identity_document_types',
                'name' => 'Типы документов, удостоверяющих личность',
                'description' => 'Foundation-справочник типов документов личности с подготовкой к ФИС mapping.',
                'items' => [
                    ['code' => 'russian_passport', 'name' => 'Паспорт гражданина РФ', 'metadata' => ['fis_reference' => 'IdentityDocumentTypeID']],
                    ['code' => 'birth_certificate', 'name' => 'Свидетельство о рождении', 'metadata' => ['fis_reference' => 'IdentityDocumentTypeID']],
                    ['code' => 'foreign_identity', 'name' => 'Иностранный документ', 'metadata' => ['fis_reference' => 'IdentityDocumentTypeID']],
                    ['code' => 'temporary_identity', 'name' => 'Временное удостоверение личности', 'metadata' => ['fis_reference' => 'IdentityDocumentTypeID']],
                    ['code' => 'other_identity', 'name' => 'Иной документ', 'metadata' => ['fis_reference' => 'IdentityDocumentTypeID']],
                ],
            ],
            [
                'code' => 'admission_education_document_types',
                'name' => 'Типы документов об образовании',
                'description' => 'Foundation-справочник типов документов об образовании с подготовкой к XSD ФИС.',
                'items' => [
                    ['code' => 'basic_general_certificate', 'name' => 'Аттестат об основном общем образовании', 'metadata' => ['fis_type' => 'TSchoolCertificateDocument']],
                    ['code' => 'secondary_general_certificate', 'name' => 'Аттестат о среднем общем образовании', 'metadata' => ['fis_type' => 'TSchoolGeneralCertificateDocument']],
                    ['code' => 'spo_diploma', 'name' => 'Диплом СПО', 'metadata' => ['fis_type' => 'TMiddleEduDiplomaDocument']],
                    ['code' => 'npo_diploma', 'name' => 'Диплом НПО', 'metadata' => ['fis_type' => 'TBasicDiplomaDocument']],
                    ['code' => 'foreign_education_document', 'name' => 'Иностранный документ об образовании', 'metadata' => ['fis_type' => 'TForeignStateEduDocument']],
                    ['code' => 'academic_certificate', 'name' => 'Академическая справка', 'metadata' => ['fis_type' => 'TAcademicDiplomaDocument']],
                    ['code' => 'other_education', 'name' => 'Иной документ об образовании', 'metadata' => ['fis_type' => 'TEduCustomDocument']],
                ],
            ],
            [
                'code' => 'admission_document_verification_statuses',
                'name' => 'Статусы проверки структурированных документов',
                'description' => 'Статусы проверки реквизитов документа, отдельные от статуса файла.',
                'items' => [
                    ['code' => 'received', 'name' => 'Получен', 'metadata' => ['tone' => 'info']],
                    ['code' => 'pending_review', 'name' => 'Ожидает проверки', 'metadata' => ['tone' => 'warning']],
                    ['code' => 'verified', 'name' => 'Проверен', 'metadata' => ['tone' => 'success']],
                    ['code' => 'rejected', 'name' => 'Отклонён', 'metadata' => ['tone' => 'danger']],
                    ['code' => 'replacement_required', 'name' => 'Требует замены', 'metadata' => ['tone' => 'warning']],
                    ['code' => 'archived', 'name' => 'Архивирован', 'metadata' => ['tone' => 'neutral']],
                ],
            ],
            [
                'code' => 'admission_document_file_categories',
                'name' => 'Категории файлов документов абитуриента',
                'description' => 'Типы образов документа в private storage.',
                'items' => [
                    ['code' => 'main_spread', 'name' => 'Основной разворот'],
                    ['code' => 'registration', 'name' => 'Регистрация'],
                    ['code' => 'attachment', 'name' => 'Приложение'],
                    ['code' => 'front_side', 'name' => 'Первая сторона'],
                    ['code' => 'back_side', 'name' => 'Вторая сторона'],
                    ['code' => 'other', 'name' => 'Другое'],
                ],
            ],
            [
                'code' => 'education_levels',
                'name' => 'Уровни образования',
                'description' => 'Уровни образования для документов абитуриента.',
                'items' => [
                    ['code' => 'basic_general', 'name' => 'Основное общее образование'],
                    ['code' => 'secondary_general', 'name' => 'Среднее общее образование'],
                    ['code' => 'secondary_vocational', 'name' => 'Среднее профессиональное образование'],
                    ['code' => 'primary_vocational', 'name' => 'Начальное профессиональное образование'],
                    ['code' => 'higher', 'name' => 'Высшее образование'],
                ],
            ],
        ];
    }
};
