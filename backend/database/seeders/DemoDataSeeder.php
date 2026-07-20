<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DemoDataSeeder extends Seeder
{
    public const EMAIL_DOMAIN = 'demo.college-portal.local';
    public const PREFIX = 'DEMO';
    public const STUDENTS = 600;
    public const TEACHERS = 70;
    public const GROUPS = 30;

    private array $departments = [];
    private array $positions = [];
    private array $programs = [];
    private array $curricula = [];
    private array $subjects = [];
    private array $classrooms = [];
    private array $teachers = [];
    private array $groups = [];
    private array $studentsByGroup = [];
    private array $lessonTypes = [];
    private array $gradeTypes = [];
    private ?int $systemUserId = null;

    public function run(): void
    {
        $this->seedDemo();
    }

    public function seedDemo(): array
    {
        $this->guardProduction('Создание демонстрационной базы запрещено в production.');

        return DB::transaction(function (): array {
            $this->clearDemo(false);
            $this->seedSettings();
            $this->seedReferences();
            $this->seedRolesAndUsers();
            $this->seedDepartmentsAndPositions();
            $this->seedPrograms();
            $this->seedSubjectsAndCurricula();
            $this->seedClassrooms();
            $this->seedTeachers();
            $this->seedGroupsAndStudents();
            $this->seedTeachingLoad();
            $this->seedScheduleJournalAttendanceAndGrades();
            $this->seedAdmissions();
            $this->seedAccessEvents();
            $this->seedAudit();

            return $this->summary();
        });
    }

    public function resetDemo(): array
    {
        $this->guardProduction('Очистка демо-данных запрещена в production.');

        return DB::transaction(function (): array {
            $deleted = $this->clearDemo(true);
            $this->setting('demo', 'mode_enabled', false, 'boolean', true, 'Включает демонстрационный режим.');

            return ['deleted' => $deleted, 'summary' => $this->summary()];
        });
    }

    public function summary(): array
    {
        return [
            'demo_mode' => filter_var(json_decode((string) DB::table('settings')->where('group', 'demo')->where('key', 'mode_enabled')->value('value'), true) ?? false, FILTER_VALIDATE_BOOL),
            'students' => DB::table('students')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->count(),
            'teachers' => DB::table('teachers')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->count(),
            'groups' => DB::table('groups')->where('name', 'like', self::PREFIX.'-GR-%')->count(),
            'subjects' => DB::table('subjects')->where('code', 'like', self::PREFIX.'-SUBJ-%')->count(),
            'classrooms' => DB::table('classrooms')->where('building', self::PREFIX.' учебный корпус')->count(),
            'departments' => DB::table('departments')->where('code', 'like', self::PREFIX.'-DEP-%')->count(),
            'specialties' => DB::table('specialties')->where('code', 'like', self::PREFIX.'-%')->count(),
            'schedule_lessons' => DB::table('schedule_lessons')->where('topic', 'like', '[DEMO-002]%')->count(),
            'journal_lessons' => DB::table('journal_lessons')->where('teacher_comment', 'like', '[DEMO-002]%')->count(),
            'journal_attendance' => DB::table('journal_attendance')->where('source', 'demo')->count(),
            'journal_grades' => DB::table('journal_grades')->where('comment', 'like', '[DEMO-002]%')->count(),
            'access_events' => DB::table('access_events')->where('device_name', 'like', self::PREFIX.'-%')->count(),
            'applicant_applications' => DB::table('applicant_applications')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->count(),
        ];
    }

    public function clearDemo(bool $includeUsers = true): array
    {
        $studentIds = DB::table('students')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->pluck('id');
        $teacherIds = DB::table('teachers')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->pluck('id');
        $personIds = DB::table('people')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->pluck('id');
        $groupIds = DB::table('groups')->where('name', 'like', self::PREFIX.'-GR-%')->pluck('id');
        $subjectIds = DB::table('subjects')->where('code', 'like', self::PREFIX.'-SUBJ-%')->pluck('id');
        $classroomIds = DB::table('classrooms')->where('building', self::PREFIX.' учебный корпус')->pluck('id');
        $curriculumIds = DB::table('curricula')->where('code', 'like', self::PREFIX.'-CUR-%')->pluck('id');
        $programIds = DB::table('education_programs')->where('name', 'like', self::PREFIX.' %')->pluck('id');
        $specialtyIds = DB::table('specialties')->where('code', 'like', self::PREFIX.'-%')->pluck('id');
        $applicationIds = DB::table('applicant_applications')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->pluck('id');
        $identityIds = DB::table('digital_identities')->where(fn ($q) => $q->whereIn('entity_id', $studentIds)->where('entity_type', 'student'))->orWhere(fn ($q) => $q->whereIn('entity_id', $teacherIds)->where('entity_type', 'teacher'))->pluck('id');
        $scheduleLessonIds = DB::table('schedule_lessons')->whereIn('group_id', $groupIds)->orWhereIn('teacher_id', $teacherIds)->orWhereIn('subject_id', $subjectIds)->orWhere('topic', 'like', '[DEMO-002]%')->pluck('id');
        $scheduleEntryIds = DB::table('schedule_entries')->whereIn('group_id', $groupIds)->orWhereIn('teacher_id', $teacherIds)->orWhereIn('subject_id', $subjectIds)->orWhere('source', 'demo')->pluck('id');
        $journalLessonIds = DB::table('journal_lessons')->whereIn('schedule_entry_id', $scheduleEntryIds)->orWhereIn('legacy_schedule_lesson_id', $scheduleLessonIds)->orWhere('teacher_comment', 'like', '[DEMO-002]%')->pluck('id');
        $teachingLoadIds = DB::table('teaching_loads')->whereIn('group_id', $groupIds)->orWhereIn('teacher_id', $teacherIds)->orWhere('description', 'like', '[DEMO-002]%')->pluck('id');
        $employeeIds = DB::table('employees')->whereIn('person_id', $personIds)->orWhere('employee_number', 'like', self::PREFIX.'-EMP-%')->pluck('id');
        $templateIds = DB::table('schedule_templates')->whereIn('group_id', $groupIds)->pluck('id');

        $deleted = [];
        $deleted['journal_grades'] = DB::table('journal_grades')->whereIn('journal_lesson_id', $journalLessonIds)->delete();
        $deleted['journal_attendance'] = DB::table('journal_attendance')->whereIn('journal_lesson_id', $journalLessonIds)->delete();
        $deleted['journal_lessons'] = DB::table('journal_lessons')->whereIn('id', $journalLessonIds)->delete();
        $deleted['grades'] = DB::table('grades')->whereIn('schedule_lesson_id', $scheduleLessonIds)->delete();
        $deleted['attendance'] = DB::table('attendance')->whereIn('schedule_lesson_id', $scheduleLessonIds)->delete();
        $deleted['schedule_lessons'] = DB::table('schedule_lessons')->whereIn('id', $scheduleLessonIds)->delete();
        DB::table('schedule_template_entries')->whereIn('schedule_template_id', $templateIds)->delete();
        $deleted['schedule_templates'] = DB::table('schedule_templates')->whereIn('id', $templateIds)->delete();
        $deleted['schedule_entries'] = DB::table('schedule_entries')->whereIn('id', $scheduleEntryIds)->delete();
        $deleted['teaching_load_items'] = DB::table('teaching_load_items')->whereIn('teaching_load_id', $teachingLoadIds)->delete();
        $deleted['teaching_loads'] = DB::table('teaching_loads')->whereIn('id', $teachingLoadIds)->delete();
        $deleted['access_events'] = DB::table('access_events')->whereIn('digital_identity_id', $identityIds)->orWhere('device_name', 'like', self::PREFIX.'-%')->delete();
        $deleted['digital_identities'] = DB::table('digital_identities')->whereIn('id', $identityIds)->delete();
        $deleted['applicant_documents'] = DB::table('applicant_application_documents')->whereIn('applicant_application_id', $applicationIds)->delete();
        $deleted['applicant_applications'] = DB::table('applicant_applications')->whereIn('id', $applicationIds)->delete();
        $deleted['curriculum_subjects'] = DB::table('curriculum_subjects')->whereIn('curriculum_id', $curriculumIds)->delete();
        $deleted['curricula'] = DB::table('curricula')->whereIn('id', $curriculumIds)->delete();
        DB::table('subject_teacher')->whereIn('subject_id', $subjectIds)->orWhereIn('teacher_id', $teacherIds)->delete();
        $deleted['students'] = DB::table('students')->whereIn('id', $studentIds)->delete();
        $deleted['teachers'] = DB::table('teachers')->whereIn('id', $teacherIds)->delete();
        DB::table('employee_status_periods')->whereIn('employee_id', $employeeIds)->delete();
        DB::table('employee_assignments')->whereIn('employee_id', $employeeIds)->delete();
        DB::table('departments')->whereIn('head_employee_id', $employeeIds)->update(['head_employee_id' => null]);
        $deleted['employees'] = DB::table('employees')->whereIn('id', $employeeIds)->delete();
        $deleted['groups'] = DB::table('groups')->whereIn('id', $groupIds)->delete();
        $deleted['subjects'] = DB::table('subjects')->whereIn('id', $subjectIds)->delete();
        $deleted['classrooms'] = DB::table('classrooms')->whereIn('id', $classroomIds)->delete();
        $deleted['education_programs'] = DB::table('education_programs')->whereIn('id', $programIds)->delete();
        $deleted['specialties'] = DB::table('specialties')->whereIn('id', $specialtyIds)->delete();
        $deleted['positions'] = DB::table('positions')->where('code', 'like', self::PREFIX.'-POS-%')->delete();
        $deleted['departments'] = DB::table('departments')->where('code', 'like', self::PREFIX.'-DEP-%')->delete();
        $deleted['people'] = DB::table('people')->whereIn('id', $personIds)->delete();
        $deleted['audit_logs'] = DB::table('audit_logs')
            ->where('module', 'demo_data')
            ->orWhere('user_agent', 'DEMO-002 seeder')
            ->delete();
        if ($includeUsers) {
            DB::table('role_user')->whereIn('user_id', DB::table('users')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->pluck('id'))->delete();
            $deleted['users'] = DB::table('users')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->delete();
        }

        return $deleted;
    }
    private function seedSettings(): void
    {
        $this->setting('demo', 'mode_enabled', true, 'boolean', true, 'Включает демонстрационный режим.');
        $this->setting('demo', 'banner_text', 'Демонстрационный режим', 'string', true, 'Текст баннера демонстрационного стенда.');
        $this->setting('demo', 'portal_url', 'https://192.168.34.104:5443', 'url', true, 'Адрес DEV-портала.');
        $this->setting('demo', 'notifications', [
            ['id' => 'demo-1', 'title' => 'Демонстрационный режим', 'text' => 'Все записи синтетические и не содержат реальных персональных данных.', 'tone' => 'warning'],
            ['id' => 'demo-2', 'title' => 'Расписание на неделю', 'text' => 'Сформированы занятия, журнал, оценки и посещаемость.', 'tone' => 'info'],
            ['id' => 'demo-3', 'title' => 'Проходная', 'text' => 'Последние события проходной подготовлены для smoke-показа.', 'tone' => 'neutral'],
        ], 'json', true, 'Демо-уведомления мобильного кабинета.');
        $this->setting('general', 'college_full_name', 'Демонстрационный колледж искусств CollegePortal', 'string', true, 'Синтетическое название DEV-стенда.');
        $this->setting('general', 'college_short_name', 'Демо-колледж', 'string', true, 'Синтетическое краткое название DEV-стенда.');
    }

    private function seedReferences(): void
    {
        $this->lessonTypes = $this->referenceItems('lesson_types', 'Типы занятий', ['lecture' => 'Лекция', 'practice' => 'Практика', 'laboratory' => 'Лабораторная', 'consultation' => 'Консультация']);
        $this->gradeTypes = $this->referenceItems('journal_grade_values', 'Значения оценок журнала', ['grade_5' => '5', 'grade_4' => '4', 'grade_3' => '3', 'pass' => 'Зачет']);
        $this->referenceItems('applicant_document_types', 'Документы абитуриента', ['passport' => 'Документ, удостоверяющий личность', 'education' => 'Документ об образовании', 'photo' => 'Фотография', 'medical' => 'Медицинская справка', 'consent' => 'Согласие на обработку данных', 'portfolio' => 'Портфолио']);
    }

    private function seedRolesAndUsers(): void
    {
        $roles = ['admin' => 'Администратор', 'director' => 'Директор', 'hr' => 'Отдел кадров', 'study' => 'Учебная часть', 'teacher' => 'Педагог', 'student' => 'Студент', 'security' => 'Оператор проходной'];
        foreach ($roles as $code => $name) {
            $this->id('roles', ['code' => $code], ['name' => $name, 'description' => 'DEMO role']);
        }
        $adminRoleId = DB::table('roles')->where('code', 'admin')->value('id');
        $this->systemUserId = $this->id('users', ['email' => 'demo.admin@'.self::EMAIL_DOMAIN], ['role_id' => $adminRoleId, 'name' => 'ДемоАдминистратор Системный', 'password' => Hash::make(Str::random(48)), 'is_active' => true]);
        DB::table('role_user')->updateOrInsert(['user_id' => $this->systemUserId, 'role_id' => $adminRoleId], ['is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function seedDepartmentsAndPositions(): void
    {
        foreach (['Учебная часть', 'Отдел кадров', 'Приемная комиссия', 'Музыкальное отделение', 'Театральное отделение', 'Художественное отделение'] as $index => $name) {
            $this->departments[] = $this->id('departments', ['code' => sprintf('%s-DEP-%02d', self::PREFIX, $index + 1)], ['name' => self::PREFIX.' '.$name, 'type' => $index < 3 ? 'administrative' : 'academic', 'is_active' => true]);
        }
        foreach ([['teacher', 'Преподаватель', true], ['methodist', 'Методист', false], ['security', 'Специалист проходной', false], ['hr', 'Специалист отдела кадров', false]] as [$code, $name, $teaching]) {
            $this->positions[$code] = $this->id('positions', ['code' => self::PREFIX.'-POS-'.strtoupper($code)], ['name' => self::PREFIX.' '.$name, 'category' => $teaching ? 'teaching' : 'administrative', 'is_teaching_position' => $teaching, 'is_active' => true]);
        }
    }

    private function seedPrograms(): void
    {
        $items = [
            ['01', 'Музыкальное исполнительство', 'Артист-преподаватель'],
            ['02', 'Вокальное искусство', 'Артист-вокалист'],
            ['03', 'Хоровое дирижирование', 'Дирижер хора'],
            ['04', 'Теория музыки', 'Преподаватель теории музыки'],
            ['05', 'Народное художественное творчество', 'Руководитель коллектива'],
            ['06', 'Социально-культурная деятельность', 'Менеджер СКД'],
        ];
        foreach ($items as $index => [$suffix, $name, $qualification]) {
            $specialtyId = $this->id('specialties', ['code' => sprintf('%s-53.02.%02d', self::PREFIX, $index + 1)], ['name' => self::PREFIX.' '.$name, 'education_level' => 'СПО', 'qualification' => $qualification, 'normative_study_years' => 3.8, 'description' => 'Синтетическая специальность DEMO-002.']);
            $programId = $this->id('education_programs', ['specialty_id' => $specialtyId, 'name' => self::PREFIX.' программа '.$name, 'year_start' => 2026, 'study_form' => 'Очная'], ['study_years' => 3.8, 'is_active' => true, 'description' => 'Синтетическая программа DEMO-002.']);
            $curriculumId = $this->id('curricula', ['code' => sprintf('%s-CUR-%02d', self::PREFIX, $index + 1)], ['education_program_id' => $programId, 'name' => self::PREFIX.' учебный план '.$name, 'qualification' => $qualification, 'year_start' => 2026, 'status' => 'active', 'description' => '[DEMO-002] Учебный план.', 'competencies' => json_encode(['DEMO-PC-1', 'DEMO-PC-2'], JSON_UNESCAPED_UNICODE)]);
            $this->programs[] = $programId;
            $this->curricula[] = $curriculumId;
        }
    }

    private function seedSubjectsAndCurricula(): void
    {
        $names = ['Сольфеджио', 'Музыкальная литература', 'Специальность', 'Ансамбль', 'Хор', 'История искусств', 'Сценическая речь', 'Основы композиции', 'Фортепиано', 'Культурология', 'Ритмика', 'Методика преподавания', 'Информатика', 'Безопасность жизнедеятельности', 'Иностранный язык', 'Проектная деятельность', 'Исполнительская практика', 'Концертмейстерский класс', 'Теория драмы', 'Режиссура', 'Живопись', 'Рисунок', 'Менеджмент культуры', 'Документационное обеспечение'];
        foreach ($names as $index => $name) {
            $this->subjects[] = $this->id('subjects', ['code' => sprintf('%s-SUBJ-%02d', self::PREFIX, $index + 1)], ['name' => self::PREFIX.' '.$name, 'department' => self::PREFIX.' отделение '.(($index % 3) + 1), 'description' => '[DEMO-002] Синтетическая дисциплина.']);
        }
        foreach ($this->curricula as $curriculumIndex => $curriculumId) {
            foreach (array_slice($this->subjects, $curriculumIndex * 3, 8) as $sequence => $subjectId) {
                $this->id('curriculum_subjects', ['curriculum_id' => $curriculumId, 'semester' => ($sequence % 2) + 1, 'subject_id' => $subjectId], ['lecture_hours' => 18, 'practice_hours' => 36, 'laboratory_hours' => $sequence % 3 === 0 ? 12 : 0, 'independent_hours' => 18, 'total_hours' => 72 + ($sequence % 3 === 0 ? 12 : 0), 'control_type' => $sequence % 2 === 0 ? 'exam' : 'credit', 'sequence' => $sequence + 1, 'is_optional' => false, 'competencies' => json_encode(['DEMO-PC-'.($sequence + 1)], JSON_UNESCAPED_UNICODE)]);
            }
        }
    }

    private function seedClassrooms(): void
    {
        foreach (range(1, 40) as $index) {
            $this->classrooms[] = $this->id('classrooms', ['number' => sprintf('D-%03d', $index), 'building' => self::PREFIX.' учебный корпус'], ['floor' => (int) ceil($index / 10), 'capacity' => 16 + ($index % 5) * 4, 'type' => $index % 4 === 0 ? 'Зал' : 'Аудитория', 'description' => '[DEMO-002] Синтетическая аудитория.']);
        }
    }

    private function seedTeachers(): void
    {
        $teacherRoleId = DB::table('roles')->where('code', 'teacher')->value('id');
        foreach (range(1, self::TEACHERS) as $index) {
            $email = sprintf('teacher%03d@%s', $index, self::EMAIL_DOMAIN);
            $personId = $this->id('people', ['email' => $email], ['last_name' => sprintf('ДемоПреподаватель%03d', $index), 'first_name' => 'Учебный', 'birth_date' => Carbon::create(1980 + ($index % 18), ($index % 12) + 1, ($index % 25) + 1)->toDateString(), 'gender' => $index % 2 ? 'female' : 'male', 'citizenship' => 'Демо', 'status' => 'active']);
            $userId = $this->id('users', ['email' => $email], ['person_id' => $personId, 'person_type' => 'teacher', 'name' => sprintf('ДемоПреподаватель%03d Учебный', $index), 'role_id' => $teacherRoleId, 'password' => Hash::make(Str::random(48)), 'is_active' => true]);
            DB::table('role_user')->updateOrInsert(['user_id' => $userId, 'role_id' => $teacherRoleId], ['is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
            $departmentId = $this->departments[3 + ($index % 3)] ?? $this->departments[0];
            $teacherId = $this->id('teachers', ['email' => $email], ['person_id' => $personId, 'user_id' => $userId, 'last_name' => sprintf('ДемоПреподаватель%03d', $index), 'first_name' => 'Учебный', 'position' => self::PREFIX.' преподаватель', 'department' => self::PREFIX.' отделение '.(($index % 3) + 1), 'is_active' => true]);
            foreach (array_slice($this->subjects, $index % count($this->subjects), 4) as $subjectId) {
                DB::table('subject_teacher')->updateOrInsert(['subject_id' => $subjectId, 'teacher_id' => $teacherId], []);
            }
            $employeeId = $this->id('employees', ['employee_number' => sprintf('%s-EMP-%03d', self::PREFIX, $index)], ['person_id' => $personId, 'status' => $index % 17 === 0 ? 'vacation' : 'active', 'employment_type' => 'full_time', 'hired_at' => '2024-09-01', 'primary_department_id' => $departmentId, 'primary_position_id' => $this->positions['teacher'], 'workload_rate' => 1, 'is_teacher' => true, 'comment' => '[DEMO-002] Синтетический сотрудник.']);
            DB::table('employee_assignments')->updateOrInsert(['employee_id' => $employeeId, 'department_id' => $departmentId, 'position_id' => $this->positions['teacher']], ['employment_type' => 'full_time', 'rate' => 1, 'started_at' => '2024-09-01', 'is_primary' => true, 'order_number' => self::PREFIX.'-ORDER-'.$index, 'order_date' => '2024-08-25', 'comment' => '[DEMO-002] Назначение.', 'created_at' => now(), 'updated_at' => now()]);
            $this->teachers[] = $teacherId;
        }
    }
    private function seedGroupsAndStudents(): void
    {
        $studentRoleId = DB::table('roles')->where('code', 'student')->value('id');
        foreach (range(1, self::GROUPS) as $index) {
            $programId = $this->programs[($index - 1) % count($this->programs)];
            $curriculumId = $this->curricula[($index - 1) % count($this->curricula)];
            $groupId = $this->id('groups', ['name' => sprintf('%s-GR-%02d', self::PREFIX, $index)], ['specialty' => self::PREFIX.' специальность '.(($index % 6) + 1), 'education_program_id' => $programId, 'curriculum_id' => $curriculumId, 'course' => (($index - 1) % 4) + 1, 'year_start' => 2026 - (($index - 1) % 4), 'curator_id' => $this->teachers[($index - 1) % count($this->teachers)]]);
            $this->groups[] = $groupId;
            $this->studentsByGroup[$groupId] = [];
        }
        foreach (range(1, self::STUDENTS) as $index) {
            $groupId = $this->groups[($index - 1) % count($this->groups)];
            $email = sprintf('student%04d@%s', $index, self::EMAIL_DOMAIN);
            $personId = $this->id('people', ['email' => $email], ['last_name' => sprintf('ДемоСтудент%04d', $index), 'first_name' => 'Учебный', 'birth_date' => Carbon::create(2007 + ($index % 4), ($index % 12) + 1, ($index % 25) + 1)->toDateString(), 'gender' => $index % 2 ? 'female' : 'male', 'citizenship' => 'Демо', 'status' => 'active']);
            $userId = null;
            if ($index <= 40) {
                $userId = $this->id('users', ['email' => $email], ['person_id' => $personId, 'person_type' => 'student', 'name' => sprintf('ДемоСтудент%04d Учебный', $index), 'role_id' => $studentRoleId, 'password' => Hash::make(Str::random(48)), 'is_active' => true]);
                DB::table('role_user')->updateOrInsert(['user_id' => $userId, 'role_id' => $studentRoleId], ['is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            $group = DB::table('groups')->where('id', $groupId)->first();
            $studentId = $this->id('students', ['email' => $email], ['person_id' => $personId, 'user_id' => $userId, 'group_id' => $groupId, 'course' => $group->course, 'last_name' => sprintf('ДемоСтудент%04d', $index), 'first_name' => 'Учебный', 'birth_date' => Carbon::create(2007 + ($index % 4), ($index % 12) + 1, ($index % 25) + 1)->toDateString(), 'status' => $index % 53 === 0 ? 'academic_leave' : 'active', 'enrollment_date' => Carbon::create($group->year_start, 9, 1)->toDateString(), 'education_form' => 'Очная', 'funding_form' => $index % 5 === 0 ? 'contract' : 'budget']);
            $this->studentsByGroup[$groupId][] = $studentId;
        }
    }

    private function seedTeachingLoad(): void
    {
        foreach ($this->groups as $groupIndex => $groupId) {
            $teacherId = $this->teachers[$groupIndex % count($this->teachers)];
            $group = DB::table('groups')->where('id', $groupId)->first();
            $loadId = $this->id('teaching_loads', ['academic_year' => '2026/2027', 'group_id' => $groupId, 'teacher_id' => $teacherId], ['curriculum_id' => $group->curriculum_id, 'status' => 'approved', 'description' => '[DEMO-002] Нагрузка.', 'generated_at' => now(), 'generated_by' => $this->systemUserId]);
            foreach (array_slice($this->subjects, $groupIndex % count($this->subjects), 6) as $subjectIndex => $subjectId) {
                $assignedTeacherId = $this->teachers[($groupIndex + $subjectIndex) % count($this->teachers)];
                $this->id('teaching_load_items', ['teaching_load_id' => $loadId, 'subject_id' => $subjectId, 'group_id' => $groupId, 'semester' => 1, 'load_type' => 'Аудиторная'], ['teacher_id' => $assignedTeacherId, 'hours_total' => 72, 'planned_hours' => 72, 'assigned_hours' => 72, 'unassigned_hours' => 0, 'overassigned_hours' => 0, 'assignment_status' => 'assigned', 'source' => 'demo', 'sort_order' => $subjectIndex + 1]);
            }
        }
    }

    private function seedScheduleJournalAttendanceAndGrades(): void
    {
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $times = [['09:00', '10:30'], ['10:45', '12:15'], ['13:00', '14:30']];
        foreach ($this->groups as $groupIndex => $groupId) {
            foreach (range(0, 4) as $dayOffset) {
                $date = $weekStart->copy()->addDays($dayOffset);
                foreach ($times as $lessonIndex => [$start, $end]) {
                    $subjectId = $this->subjects[($groupIndex + $dayOffset + $lessonIndex) % count($this->subjects)];
                    $teacherId = $this->teachers[($groupIndex * 3 + $dayOffset + $lessonIndex) % count($this->teachers)];
                    $classroomId = $this->classrooms[($groupIndex * 5 + $dayOffset + $lessonIndex) % count($this->classrooms)];
                    $subjectName = DB::table('subjects')->where('id', $subjectId)->value('name');
                    $topic = sprintf('[DEMO-002] %s: тема %d.%d', $subjectName, $dayOffset + 1, $lessonIndex + 1);
                    $legacyId = $this->id('schedule_lessons', ['group_id' => $groupId, 'teacher_id' => $teacherId, 'subject_id' => $subjectId, 'lesson_date' => $date->toDateString(), 'starts_at' => $start], ['classroom_id' => $classroomId, 'ends_at' => $end, 'lesson_type' => 'lesson', 'topic' => $topic]);
                    $entryId = $this->id('schedule_entries', ['group_id' => $groupId, 'teacher_id' => $teacherId, 'subject_id' => $subjectId, 'date' => $date->toDateString(), 'starts_at' => $start], ['academic_year' => '2026/2027', 'semester' => 1, 'day_of_week' => $date->dayOfWeekIso, 'week_type' => 'demo', 'lesson_number' => $lessonIndex + 1, 'ends_at' => $end, 'classroom_id' => $classroomId, 'lesson_type_id' => $this->lessonTypes['practice'] ?? null, 'status' => 'published', 'source' => 'demo', 'is_replacement' => false, 'comment' => '[DEMO-002] Расписание.', 'created_by' => $this->systemUserId, 'updated_by' => $this->systemUserId]);
                    DB::table('schedule_lessons')->where('id', $legacyId)->update(['schedule_entry_id' => $entryId]);
                    $journalId = $this->id('journal_lessons', ['schedule_entry_id' => $entryId], ['legacy_schedule_lesson_id' => $legacyId, 'group_id' => $groupId, 'subject_id' => $subjectId, 'teacher_id' => $teacherId, 'lesson_date' => $date->toDateString(), 'starts_at' => $start, 'ends_at' => $end, 'lesson_type_id' => $this->lessonTypes['practice'] ?? null, 'topic' => $topic, 'homework' => '[DEMO-002] Подготовить короткое задание по теме занятия.', 'homework_due_at' => $date->copy()->addWeek()->setTime(18, 0), 'teacher_comment' => '[DEMO-002] Журнал заполнен.', 'status' => $date->isFuture() ? 'planned' : 'completed', 'opened_at' => $date->copy()->setTimeFromTimeString($start), 'completed_at' => $date->isFuture() ? null : $date->copy()->setTimeFromTimeString($end)]);
                    foreach (array_slice($this->studentsByGroup[$groupId], 0, 24) as $studentIndex => $studentId) {
                        $status = $studentIndex % 17 === 0 ? 'absent' : ($studentIndex % 11 === 0 ? 'late' : 'present');
                        $minutesLate = $status === 'late' ? 5 + ($studentIndex % 18) : null;
                        $this->id('journal_attendance', ['journal_lesson_id' => $journalId, 'student_id' => $studentId], ['status' => $status, 'minutes_late' => $minutesLate, 'comment' => '[DEMO-002] Посещаемость.', 'source' => 'demo', 'marked_by' => $this->systemUserId, 'marked_at' => $date->copy()->setTimeFromTimeString($end)]);
                        $this->id('attendance', ['schedule_lesson_id' => $legacyId, 'student_id' => $studentId], ['status' => $status, 'comment' => '[DEMO-002] Посещаемость.']);
                        if ($lessonIndex === 0 && $dayOffset < 3 && $studentIndex % 3 !== 0) {
                            $grade = ['5', '4', '3'][$studentIndex % 3];
                            $this->id('journal_grades', ['journal_lesson_id' => $journalId, 'student_id' => $studentId, 'grade_type_id' => $this->gradeTypes['grade_'.$grade] ?? null], ['value' => $grade, 'weight' => 1, 'comment' => '[DEMO-002] Оценка.', 'marked_by' => $this->systemUserId, 'marked_at' => $date->copy()->setTimeFromTimeString($end)]);
                            $this->id('grades', ['schedule_lesson_id' => $legacyId, 'student_id' => $studentId, 'grade_type' => 'classwork'], ['grade' => $grade, 'comment' => '[DEMO-002] Оценка.']);
                        }
                    }
                }
            }
        }
    }
    private function seedAdmissions(): void
    {
        $documentTypeIds = DB::table('reference_items')->whereIn('catalog_id', DB::table('reference_catalogs')->where('code', 'applicant_document_types')->pluck('id'))->limit(6)->pluck('id');
        foreach (range(1, 90) as $index) {
            $email = sprintf('applicant%03d@%s', $index, self::EMAIL_DOMAIN);
            $personId = $this->id('people', ['email' => $email], ['last_name' => sprintf('ДемоАбитуриент%03d', $index), 'first_name' => 'Приемный', 'birth_date' => Carbon::create(2008 + ($index % 3), ($index % 12) + 1, ($index % 25) + 1)->toDateString(), 'status' => 'applicant']);
            $status = $index % 9 === 0 ? 'enrolled' : ($index % 5 === 0 ? 'in_review' : ($index % 4 === 0 ? 'documents_pending' : 'new'));
            $applicationId = $this->id('applicant_applications', ['email' => $email], ['person_id' => $personId, 'education_program_id' => $this->programs[($index - 1) % count($this->programs)], 'last_name' => sprintf('ДемоАбитуриент%03d', $index), 'first_name' => 'Приемный', 'birth_date' => Carbon::create(2008 + ($index % 3), ($index % 12) + 1, ($index % 25) + 1)->toDateString(), 'education_base' => $index % 3 === 0 ? 'after_11' : 'after_9', 'education_form' => 'Очная', 'funding_form' => $index % 5 === 0 ? 'contract' : 'budget', 'status' => $status, 'submitted_at' => Carbon::now()->subDays($index % 30)->toDateString(), 'certificate_average_score' => 3.5 + (($index % 15) / 10), 'achievement_score' => $index % 7, 'ranking_score' => 40 + ($index % 60), 'documents_provided' => $index % 2 === 0, 'recommended_for_enrollment' => $index % 6 === 0, 'comment' => '[DEMO-002] Синтетическое заявление.']);
            foreach ($documentTypeIds as $docIndex => $documentTypeId) {
                if ($index % 4 === 0 && $docIndex > 1) {
                    continue;
                }
                $type = DB::table('reference_items')->where('id', $documentTypeId)->first();
                $status = $index % 7 === 0 && $docIndex === 0 ? 'rejected' : ($index % 3 === 0 ? 'under_review' : 'verified');
                $this->id('applicant_application_documents', ['applicant_application_id' => $applicationId, 'document_type_id' => $documentTypeId], ['type' => $type?->code, 'title' => $type?->name, 'status' => $status, 'is_received' => true, 'received_at' => Carbon::now()->subDays($index % 20)->toDateString(), 'received_by' => $this->systemUserId, 'verified_at' => $status === 'verified' ? now() : null, 'verified_by' => $status === 'verified' ? $this->systemUserId : null, 'comment' => '[DEMO-002] Синтетический документ.', 'source' => 'demo']);
            }
        }
    }

    private function seedAccessEvents(): void
    {
        $today = Carbon::today();
        $owners = collect(DB::table('students')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->limit(180)->get(['id', 'person_id'])->map(fn ($row) => ['student', $row->id, $row->person_id]))
            ->merge(DB::table('teachers')->where('email', 'like', '%@'.self::EMAIL_DOMAIN)->limit(45)->get(['id', 'person_id'])->map(fn ($row) => ['teacher', $row->id, $row->person_id]));
        foreach ($owners as $index => [$type, $entityId, $personId]) {
            $identityId = $this->id('digital_identities', ['entity_type' => $type, 'entity_id' => $entityId], ['person_id' => $personId, 'token' => sprintf('DEMO-%s-%05d-%s', strtoupper($type), $entityId, Str::upper(Str::random(8))), 'status' => 'active', 'issued_at' => now()->subDays(20), 'expires_at' => now()->addYear()]);
            foreach (range(0, 4) as $dayOffset) {
                $date = $today->copy()->subDays($dayOffset);
                $this->id('access_events', ['digital_identity_id' => $identityId, 'event_time' => $date->copy()->setTime(8, ($index + $dayOffset) % 50), 'direction' => 'in'], ['entity_type' => $type, 'entity_id' => $entityId, 'access_point' => 'Главный вход', 'device_name' => self::PREFIX.'-HID-01', 'result' => 'allowed']);
                if (($index + $dayOffset) % 5 !== 0) {
                    $this->id('access_events', ['digital_identity_id' => $identityId, 'event_time' => $date->copy()->setTime(15, ($index + $dayOffset) % 45), 'direction' => 'out'], ['entity_type' => $type, 'entity_id' => $entityId, 'access_point' => 'Главный вход', 'device_name' => self::PREFIX.'-HID-01', 'result' => 'allowed']);
                }
            }
        }
        foreach (range(1, 12) as $index) {
            DB::table('access_events')->insert(['direction' => 'in', 'event_time' => now()->subMinutes($index * 17), 'access_point' => 'Главный вход', 'device_name' => self::PREFIX.'-HID-01', 'result' => 'denied', 'reason' => 'DEMO unknown token', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function seedAudit(): void
    {
        foreach ([['dashboard', 'demo_dashboard_opened'], ['journal', 'demo_journal_completed'], ['access', 'demo_access_scan'], ['admissions', 'demo_application_review'], ['hr', 'demo_employee_status'], ['demo_data', 'demo_database_seeded']] as $index => [$module, $action]) {
            DB::table('audit_logs')->insert(['created_at' => now()->subMinutes(($index + 1) * 11), 'user_id' => $this->systemUserId, 'action' => $action, 'entity_type' => 'demo', 'entity_id' => null, 'module' => $module, 'old_values' => null, 'new_values' => json_encode(['demo' => true]), 'ip_address' => '127.0.0.1', 'user_agent' => 'DEMO-002 seeder', 'request_id' => (string) Str::uuid()]);
        }
    }

    private function referenceItems(string $code, string $name, array $items): array
    {
        $catalogId = $this->id('reference_catalogs', ['code' => $code], ['name' => $name, 'description' => 'DEMO-002 reference catalog.', 'is_system' => true]);
        $result = [];
        $sort = 10;
        foreach ($items as $itemCode => $itemName) {
            $result[$itemCode] = $this->id('reference_items', ['catalog_id' => $catalogId, 'code' => $itemCode], ['name' => $itemName, 'sort_order' => $sort, 'is_active' => true, 'metadata' => json_encode(['is_system' => true])]);
            $sort += 10;
        }
        return $result;
    }

    private function setting(string $group, string $key, mixed $value, string $type, bool $isPublic, string $description): void
    {
        $this->id('settings', ['group' => $group, 'key' => $key], ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'type' => $type, 'is_public' => $isPublic, 'description' => $description]);
    }

    private function id(string $table, array $where, array $values): int
    {
        $payload = array_merge($where, $values, ['updated_at' => now()]);
        if (DB::table($table)->where($where)->exists()) {
            DB::table($table)->where($where)->update($payload);
        } else {
            $payload['created_at'] = now();
            DB::table($table)->insert($payload);
        }

        return (int) DB::table($table)->where($where)->value('id');
    }

    private function guardProduction(string $message): void
    {
        abort_if(app()->environment('production'), Response::HTTP_FORBIDDEN, $message);
    }
}
