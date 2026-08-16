<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Роли до сих пор заводил только `RoleSeeder`, а он выполняется при установке и
 * больше никогда: `installer/update.sh` гоняет одни миграции. Значит, роль,
 * добавленную в сидер после установки, обновлённая система не получала вовсе —
 * и не получила: `study_records` пришлось поднимать на боевом сервере руками
 * 17.08.2026, потому что миграции для неё не существовало.
 *
 * Миграция закрывает это для всех ролей сразу, а не для одной. Она **только
 * добавляет**: роль, которая уже есть, не трогается ни по названию, ни по
 * набору прав — на боевом сервере права могли править из интерфейса, и
 * выравнивать их под снимок нельзя.
 *
 * Набор прав здесь — снимок каталога на 17.08.2026, снятый с базы свежей
 * установки. Это намеренная копия, а не дублирование: миграция обязана
 * оставаться неизменной, даже когда сидер поедет дальше.
 */
return new class extends Migration
{
    private const ALL_PERMISSIONS = '*';

    private const ROLES = [
        [
            'code' => 'academic_office',
            'name' => 'Учебная часть (legacy)',
            'description' => 'Legacy-роль для совместимости.',
        ],
        [
            'code' => 'admin',
            'name' => 'Администратор',
            'description' => 'Полный доступ к системе.',
        ],
        [
            'code' => 'admission',
            'name' => 'Приемная комиссия',
            'description' => 'Работа с абитуриентами и приемными кампаниями.',
        ],
        [
            'code' => 'curator',
            'name' => 'Куратор группы',
            'description' => 'Сопровождение закрепленной учебной группы.',
        ],
        [
            'code' => 'deputy',
            'name' => 'Заместитель директора',
            'description' => 'Контроль учебного процесса, отчетов и справочников.',
        ],
        [
            'code' => 'director',
            'name' => 'Директор',
            'description' => 'Управленческий просмотр отчетов и сводок.',
        ],
        [
            'code' => 'employee',
            'name' => 'Сотрудник',
            'description' => 'Просмотр рабочего стола и личных данных.',
        ],
        [
            'code' => 'hr',
            'name' => 'Отдел кадров',
            'description' => 'Ведение сотрудников, подразделений, должностей и кадровых статусов.',
        ],
        [
            'code' => 'security',
            'name' => 'Сотрудник проходной',
            'description' => 'Сканирование QR и отчеты проходной.',
        ],
        [
            'code' => 'student',
            'name' => 'Студент',
            'description' => 'Просмотр личного кабинета, QR, расписания и оценок.',
        ],
        [
            'code' => 'study',
            'name' => 'Учебная часть 1',
            'description' => 'Расписание, замены, учебная нагрузка и учебные планы.',
        ],
        [
            'code' => 'study_records',
            'name' => 'Учебная часть 2',
            'description' => 'Контингент, журнал успеваемости, посещаемость и выпуск.',
        ],
        [
            'code' => 'teacher',
            'name' => 'Преподаватель',
            'description' => 'Работа со своим расписанием, журналом и нагрузкой.',
        ],
    ];

    private const GRANTS = [
        'academic_office' => [
            'admissions.applicant.view', 'admissions.application.view', 'admissions.choice.view',
            'admissions.documents.view', 'admissions.document.view', 'admissions.edit', 'admissions.reference.view',
            'admissions.view', 'attendance.reports', 'attendance.view', 'classrooms.create', 'classrooms.delete',
            'classrooms.update', 'classrooms.view', 'curricula.edit', 'curricula.subjects.create',
            'curricula.subjects.delete', 'curricula.subjects.update', 'curricula.subjects.view', 'curricula.view',
            'dashboard.view', 'digitalpasses.manage', 'exams.edit', 'exams.view', 'fis.export', 'fis.outbound.create',
            'fis.outbound.generate', 'fis.outbound.send_test', 'fis.outbound.status', 'fis.outbound.validate',
            'fis.outbound.view', 'fis.view', 'frdo.export', 'frdo.view', 'gate.points.manage', 'gate.reports',
            'gate.scan', 'graduation.edit', 'graduation.view', 'groups.create', 'groups.update', 'groups.view',
            'hr.calendar.view', 'hr.employees.view', 'hr.replacements.manage', 'hr.replacements.view', 'hr.reports.view',
            'hr.statuses.manage', 'import.manage', 'journal.attendance', 'journal.complete', 'journal.edit',
            'journal.export', 'journal.files', 'journal.grades', 'journal.reopen', 'journal.sign', 'journal.view',
            'journal.view_all', 'people.link', 'people.update', 'people.view', 'reference.manage', 'reference.view',
            'schedule.create', 'schedule.delete', 'schedule.manage_replacements', 'schedule.manage_templates',
            'schedule.update', 'schedule.validate', 'schedule.view', 'schedule.view_conflicts', 'schedule.view_coverage',
            'students.bulk_accounts', 'students.bulk_archive', 'students.bulk_course', 'students.bulk_education',
            'students.bulk_export', 'students.bulk_group', 'students.bulk_passes', 'students.bulk_status',
            'students.create', 'students.update', 'students.view', 'subjects.create', 'subjects.delete',
            'subjects.update', 'subjects.view', 'teachers.create', 'teachers.update', 'teachers.view',
            'teaching_load.assign', 'teaching_load.bulk_assign', 'teachingload.edit', 'teaching_load.generate',
            'teachingload.view', 'teaching_load.view_coverage', 'trash.request', 'uat.manage', 'view_own_data',
        ],
        // Администратор получает каталог целиком — перечислять его тут
        // значило бы завести второй список, который разойдётся с первым.
        'admin' => self::ALL_PERMISSIONS,
        'admission' => [
            'admissions.applicant.archive', 'admissions.applicant.create', 'admissions.applicant.manage',
            'admissions.applicant.update', 'admissions.applicant.view', 'admissions.application.create',
            'admissions.application.manage', 'admissions.application.register', 'admissions.application.update',
            'admissions.application.view', 'admissions.bulk_assign', 'admissions.bulk_documents',
            'admissions.bulk_enroll', 'admissions.bulk_export', 'admissions.bulk_recommend', 'admissions.bulk_status',
            'admissions.choice.create', 'admissions.choice.delete', 'admissions.choice.update', 'admissions.choice.view',
            'admissions.document.create', 'admissions.document.delete', 'admissions.document.download_sensitive',
            'admissions.documents.download', 'admissions.documents.receive', 'admissions.documents.reject',
            'admissions.documents.upload', 'admissions.documents.verify', 'admissions.documents.view',
            'admissions.document.update', 'admissions.document.verify', 'admissions.document.view', 'admissions.edit',
            'admissions.reference.view', 'admissions.view', 'attendance.reports', 'dashboard.view',
            'fis.outbound.create', 'fis.outbound.generate', 'fis.outbound.send_test', 'fis.outbound.status',
            'fis.outbound.validate', 'fis.outbound.view', 'groups.view', 'import.manage', 'people.create',
            'people.update', 'people.view', 'reference.manage', 'reference.view', 'students.view', 'trash.request',
        ],
        'curator' => [
            'attendance.reports', 'attendance.view', 'dashboard.view', 'exams.view', 'groups.view', 'hr.calendar.view',
            'journal.attendance', 'journal.complete', 'journal.edit', 'journal.export', 'journal.files',
            'journal.grades', 'journal.sign', 'journal.view', 'mobile.curator.view', 'mobile.teacher.view',
            'reference.view', 'schedule.view', 'students.view', 'view_own_data',
        ],
        'deputy' => [
            'admissions.applicant.view', 'admissions.application.view', 'admissions.choice.view',
            'admissions.documents.view', 'admissions.document.view', 'admissions.edit', 'admissions.reference.view',
            'admissions.view', 'attendance.reports', 'attendance.view', 'classrooms.create', 'classrooms.delete',
            'classrooms.update', 'classrooms.view', 'curricula.edit', 'curricula.subjects.create',
            'curricula.subjects.delete', 'curricula.subjects.update', 'curricula.subjects.view', 'curricula.view',
            'dashboard.view', 'digitalpasses.manage', 'exams.edit', 'exams.view', 'fis.export', 'fis.outbound.create',
            'fis.outbound.generate', 'fis.outbound.send_test', 'fis.outbound.status', 'fis.outbound.validate',
            'fis.outbound.view', 'fis.view', 'frdo.export', 'frdo.view', 'gate.points.manage', 'gate.reports',
            'gate.scan', 'graduation.edit', 'graduation.view', 'groups.create', 'groups.update', 'groups.view',
            'hr.calendar.view', 'hr.employees.view', 'hr.replacements.manage', 'hr.replacements.view', 'hr.reports.view',
            'hr.statuses.manage', 'import.manage', 'journal.attendance', 'journal.complete', 'journal.edit',
            'journal.export', 'journal.files', 'journal.grades', 'journal.reopen', 'journal.sign', 'journal.view',
            'journal.view_all', 'people.link', 'people.update', 'people.view', 'reference.manage', 'reference.view',
            'schedule.create', 'schedule.delete', 'schedule.manage_replacements', 'schedule.manage_templates',
            'schedule.update', 'schedule.validate', 'schedule.view', 'schedule.view_conflicts', 'schedule.view_coverage',
            'students.bulk_accounts', 'students.bulk_archive', 'students.bulk_course', 'students.bulk_education',
            'students.bulk_export', 'students.bulk_group', 'students.bulk_passes', 'students.bulk_status',
            'students.create', 'students.update', 'students.view', 'subjects.create', 'subjects.delete',
            'subjects.update', 'subjects.view', 'teachers.create', 'teachers.update', 'teachers.view',
            'teaching_load.assign', 'teaching_load.bulk_assign', 'teachingload.edit', 'teaching_load.generate',
            'teachingload.view', 'teaching_load.view_coverage', 'trash.request', 'uat.manage', 'view_own_data',
        ],
        'director' => [
            'admissions.applicant.view', 'admissions.application.view', 'admissions.bulk_export',
            'admissions.choice.view', 'admissions.document.download_sensitive', 'admissions.documents.download',
            'admissions.documents.view', 'admissions.document.view', 'admissions.reference.view', 'admissions.view',
            'attendance.reports', 'attendance.view', 'audit.view', 'classrooms.view', 'curricula.subjects.view',
            'curricula.view', 'dashboard.view', 'exams.view', 'fis.outbound.status', 'fis.outbound.view', 'fis.view',
            'frdo.view', 'gate.reports', 'graduation.view', 'groups.view', 'hr.calendar.view', 'hr.employees.view',
            'hr.replacements.view', 'hr.reports.view', 'journal.export', 'journal.view', 'journal.view_all',
            'people.view', 'reference.manage', 'reference.view', 'schedule.view', 'schedule.view_conflicts',
            'schedule.view_coverage', 'students.view', 'subjects.view', 'teachers.view', 'teachingload.view',
            'teaching_load.view_coverage', 'uat.manage',
        ],
        'employee' => [
            'dashboard.view', 'reference.view', 'view_own_data',
        ],
        'hr' => [
            'dashboard.view', 'gate.reports', 'hr.absences.manage', 'hr.assignments.manage', 'hr.calendar.manage',
            'hr.calendar.view', 'hr.departments.manage', 'hr.dismissals.manage', 'hr.documents.view',
            'hr.employees.create', 'hr.employees.digital_pass.issue', 'hr.employees.dismiss', 'hr.employees.update',
            'hr.employees.view', 'hr.people.match', 'hr.positions.manage', 'hr.replacements.manage',
            'hr.replacements.view', 'hr.reports.view', 'hr.statuses.manage', 'reference.view', 'teachers.view',
            'trash.request', 'view_own_data',
        ],
        'security' => [
            'attendance.reports', 'attendance.view', 'dashboard.view', 'digitalpasses.manage', 'gate.points.manage',
            'gate.reports', 'gate.scan', 'reference.view',
        ],
        'student' => [
            'attendance.view', 'dashboard.view', 'mobile.student.pass', 'mobile.student.view', 'reference.view',
            'schedule.view', 'view_own_data',
        ],
        'study' => [
            'admissions.edit', 'admissions.view', 'attendance.reports', 'attendance.view', 'classrooms.create',
            'classrooms.delete', 'classrooms.update', 'classrooms.view', 'curricula.edit', 'curricula.subjects.create',
            'curricula.subjects.delete', 'curricula.subjects.update', 'curricula.subjects.view', 'curricula.view',
            'dashboard.view', 'digitalpasses.manage', 'exams.edit', 'exams.view', 'fis.export', 'fis.view',
            'frdo.export', 'frdo.view', 'gate.points.manage', 'gate.reports', 'gate.scan', 'graduation.edit',
            'graduation.view', 'groups.create', 'groups.update', 'groups.view', 'hr.calendar.view',
            'hr.replacements.manage', 'hr.replacements.view', 'import.manage', 'people.view', 'reference.view',
            'schedule.create', 'schedule.delete', 'schedule.manage_replacements', 'schedule.manage_templates',
            'schedule.update', 'schedule.validate', 'schedule.view', 'schedule.view_conflicts', 'schedule.view_coverage',
            'students.create', 'students.update', 'students.view', 'subjects.create', 'subjects.delete',
            'subjects.update', 'subjects.view', 'teachers.create', 'teachers.update', 'teachers.view',
            'teaching_load.assign', 'teaching_load.bulk_assign', 'teachingload.edit', 'teaching_load.generate',
            'teachingload.view', 'teaching_load.view_coverage', 'trash.request', 'view_own_data',
        ],
        'study_records' => [
            'attendance.reports', 'attendance.view', 'classrooms.view', 'curricula.view', 'dashboard.view', 'exams.view',
            'frdo.view', 'graduation.edit', 'graduation.view', 'groups.create', 'groups.update', 'groups.view',
            'journal.attendance', 'journal.complete', 'journal.edit', 'journal.export', 'journal.files',
            'journal.grades', 'journal.reopen', 'journal.sign', 'journal.view', 'journal.view_all', 'people.link',
            'people.update', 'people.view', 'reference.view', 'schedule.view', 'students.bulk_accounts',
            'students.bulk_archive', 'students.bulk_course', 'students.bulk_education', 'students.bulk_export',
            'students.bulk_group', 'students.bulk_passes', 'students.bulk_status', 'students.create', 'students.update',
            'students.view', 'subjects.view', 'teachers.view', 'teachingload.view', 'trash.request', 'view_own_data',
        ],
        'teacher' => [
            'attendance.view', 'dashboard.view', 'exams.view', 'hr.calendar.view', 'journal.attendance',
            'journal.complete', 'journal.edit', 'journal.export', 'journal.files', 'journal.grades', 'journal.sign',
            'journal.view', 'mobile.teacher.view', 'reference.view', 'schedule.view', 'view_own_data',
        ],
    ];

    public function up(): void
    {
        $existing = DB::table('roles')->pluck('id', 'code');
        $permissions = DB::table('permissions')->pluck('id', 'code');

        foreach (self::ROLES as $role) {
            if ($existing->has($role['code'])) {
                continue;
            }

            $roleId = DB::table('roles')->insertGetId([
                'code' => $role['code'],
                'name' => $role['name'],
                'description' => $role['description'] !== '' ? $role['description'] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $codes = self::GRANTS[$role['code']] ?? [];
            $wanted = $codes === self::ALL_PERMISSIONS ? $permissions->keys()->all() : $codes;

            $rows = [];
            foreach ($wanted as $code) {
                // Права, которого нет в каталоге, здесь просто нет: его заводит
                // своя миграция, и порядок их выполнения не гарантирован.
                if ($permissions->has($code)) {
                    $rows[] = ['role_id' => $roleId, 'permission_id' => $permissions->get($code)];
                }
            }

            if ($rows !== []) {
                DB::table('permission_role')->insert($rows);
            }
        }
    }

    /**
     * Отката нет намеренно. Отличить роль, заведённую этой миграцией, от роли,
     * пришедшей с установкой, уже невозможно, а удаление роли с назначенными
     * людьми молча отбирает у них доступ. Роль убирают осознанно, из интерфейса.
     */
    public function down(): void
    {
    }
};
