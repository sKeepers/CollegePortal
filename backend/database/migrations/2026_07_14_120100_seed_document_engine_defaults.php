<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('document_types')->updateOrInsert(
            ['code' => 'student_enrollment_certificate'],
            [
                'name' => 'Справка, подтверждающая обучение студента',
                'description' => 'Официальная справка об обучении студента с регистрационным номером и публичной проверкой подлинности.',
                'category' => 'student_certificate',
                'entity_type' => 'student',
                'numbering_pattern' => 'СПР-{YEAR}-{NUMBER:05}',
                'requires_registration' => true,
                'requires_qr' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $typeId = DB::table('document_types')->where('code', 'student_enrollment_certificate')->value('id');

        DB::table('document_templates')->updateOrInsert(
            ['document_type_id' => $typeId, 'version' => '1.0-demo'],
            [
                'name' => 'Демонстрационный шаблон справки об обучении',
                'status' => 'active',
                'source_format' => 'docx',
                'template_path' => 'document-templates/demo/student_enrollment_certificate.docx',
                'output_formats' => json_encode(['docx', 'pdf'], JSON_UNESCAPED_UNICODE),
                'variables_schema' => json_encode([
                    'organization.full_name',
                    'organization.short_name',
                    'document.number',
                    'document.issue_date',
                    'student.full_name',
                    'student.course',
                    'student.group',
                    'student.education_form',
                    'student.funding_type',
                    'verification.url',
                ], JSON_UNESCAPED_UNICODE),
                'published_at' => now(),
                'notes' => 'Обезличенный демонстрационный шаблон без подписи, печати и персональных данных.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        $typeId = DB::table('document_types')->where('code', 'student_enrollment_certificate')->value('id');

        if ($typeId) {
            DB::table('document_templates')->where('document_type_id', $typeId)->where('version', '1.0-demo')->delete();
            DB::table('document_types')->where('id', $typeId)->delete();
        }
    }
};
