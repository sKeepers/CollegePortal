<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessEvent;
use App\Models\ApplicantApplication;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Curriculum;
use App\Models\CurriculumItem;
use App\Models\CurriculumSubject;
use App\Models\DigitalIdentity;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\EmployeeStatusPeriod;
use App\Models\Grade;
use App\Models\Group;
use App\Models\JournalAttendance;
use App\Models\JournalGrade;
use App\Models\JournalLesson;
use App\Models\Person;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeachingLoad;
use App\Models\TeachingLoadItem;
use App\Models\User;
use App\Services\AuditLogService;
use Database\Seeders\DemoDataSeeder;
use App\Support\Csv\CsvExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DemoDataController extends Controller
{
    public function status(): array
    {
        return ['data' => $this->summary()];
    }

    /**
     * Очистка и сброс демо-данных в production запрещены, а заливка — нет:
     * защитили две ручки из трёх. При этом `create` наполняет базу шестьюстами
     * демонстрационными студентами, преподавателями и событиями проходной, и
     * на боевом контуре это хуже очистки — данные не исчезают, а появляются
     * вперемешку с настоящими, и отличить их потом можно только по домену
     * почты. Ошибка в правах не должна открывать такую заливку: это второй
     * рубеж, и стоит он одну строку.
     */
    public function create(): JsonResponse
    {
        abort_if(app()->environment('production'), Response::HTTP_FORBIDDEN, 'Создание демо-данных запрещено в production.');

        Artisan::call('db:seed', [
            '--class' => DemoDataSeeder::class,
            '--force' => true,
        ]);

        $summary = $this->summary();
        AuditLogService::log('demo_data', 'create_demo', ['type' => 'demo_data', 'id' => null], null, $summary, request());

        return response()->json([
            'message' => 'Демо-данные созданы или обновлены.',
            'data' => $summary,
        ]);
    }

    public function clear(): JsonResponse
    {
        abort_if(app()->environment('production'), Response::HTTP_FORBIDDEN, 'Очистка демо-данных запрещена в production.');

        $summary = DB::transaction(function (): array {
            $studentEmails = Student::query()
                ->where('email', 'student@local')
                ->orWhere('email', 'like', 'student.demo.%@demo.college.local')
                ->pluck('email');
            $teacherEmails = Teacher::query()
                ->where('email', 'teacher@local')
                ->orWhere('email', 'like', 'teacher.demo.%@demo.college.local')
                ->pluck('email');
            $applicationEmails = ApplicantApplication::query()
                ->legacy()
                ->where('email', 'like', 'applicant.demo.%@demo.college.local')
                ->pluck('email');

            $studentIds = Student::query()->whereIn('email', $studentEmails)->pluck('id');
            $teacherIds = Teacher::query()->whereIn('email', $teacherEmails)->pluck('id');
            $studentPersonIds = Student::query()->whereIn('id', $studentIds)->whereNotNull('person_id')->pluck('person_id');
            $teacherPersonIds = Teacher::query()->whereIn('id', $teacherIds)->whereNotNull('person_id')->pluck('person_id');
            $personIds = $studentPersonIds->merge($teacherPersonIds)->unique()->values();
            $employeeIds = Employee::query()
                ->where('employee_number', 'like', 'DEMO-T%')
                ->orWhereIn('person_id', $teacherPersonIds)
                ->pluck('id');
            $groupIds = Group::query()->where('name', 'like', 'ДЕМО-%')->pluck('id');
            $subjectIds = Subject::query()
                ->where('code', 'MUS-101')
                ->orWhere('code', 'like', 'DEMO-SUB-%')
                ->pluck('id');
            $lessonIds = ScheduleLesson::query()
                ->whereIn('group_id', $groupIds)
                ->orWhereIn('teacher_id', $teacherIds)
                ->orWhereIn('subject_id', $subjectIds)
                ->pluck('id');
            $identityIds = DigitalIdentity::query()
                ->where(fn ($query) => $query->where('entity_type', DigitalIdentity::ENTITY_STUDENT)->whereIn('entity_id', $studentIds))
                ->orWhere(fn ($query) => $query->where('entity_type', DigitalIdentity::ENTITY_TEACHER)->whereIn('entity_id', $teacherIds))
                ->pluck('id');

            // Журнал, нагрузка и учебные планы появились в наборе 11.08.2026, и
            // очистка обязана снимать их первой: пока она этого не делала,
            // преподавателя держали строки нагрузки и занятия журнала, и
            // удаление падало нарушением внешнего ключа. Что набор создаёт, то
            // он и убирает — иначе стенд нельзя вернуть в исходное состояние.
            $journalLessonIds = JournalLesson::query()
                ->whereIn('legacy_schedule_lesson_id', $lessonIds)
                ->orWhereIn('teacher_id', $teacherIds)
                ->orWhereIn('group_id', $groupIds)
                ->pluck('id');
            $curriculumIds = Curriculum::query()->where('code', 'like', 'УП-ДЕМО-%')->pluck('id');
            $loadIds = TeachingLoad::query()
                ->whereIn('curriculum_id', $curriculumIds)
                ->orWhereIn('group_id', $groupIds)
                ->orWhereIn('teacher_id', $teacherIds)
                ->pluck('id');

            Group::query()->whereIn('curriculum_id', $curriculumIds)->update(['curriculum_id' => null]);

            $deleted = [
                'access_events' => AccessEvent::query()->whereIn('digital_identity_id', $identityIds)->delete(),
                'digital_identities' => DigitalIdentity::query()->whereIn('id', $identityIds)->delete(),
                'journal_grades' => JournalGrade::query()->whereIn('journal_lesson_id', $journalLessonIds)->delete(),
                'journal_attendance' => JournalAttendance::query()->whereIn('journal_lesson_id', $journalLessonIds)->delete(),
                'journal_lessons' => JournalLesson::query()->whereIn('id', $journalLessonIds)->delete(),
                'teaching_load_items' => TeachingLoadItem::query()->whereIn('teaching_load_id', $loadIds)->orWhereIn('teacher_id', $teacherIds)->delete(),
                'teaching_loads' => TeachingLoad::query()->whereIn('id', $loadIds)->delete(),
                'curriculum_subjects' => CurriculumSubject::query()->whereIn('curriculum_id', $curriculumIds)->delete(),
                'curriculum_items' => CurriculumItem::query()->whereIn('curriculum_id', $curriculumIds)->delete(),
                'curricula' => Curriculum::query()->whereIn('id', $curriculumIds)->delete(),
                'grades' => Grade::query()->whereIn('schedule_lesson_id', $lessonIds)->delete(),
                'attendance' => Attendance::query()->whereIn('schedule_lesson_id', $lessonIds)->delete(),
                'schedule_lessons' => ScheduleLesson::query()->whereIn('id', $lessonIds)->delete(),
                'employee_assignments' => EmployeeAssignment::query()->whereIn('employee_id', $employeeIds)->delete(),
                'employee_status_periods' => EmployeeStatusPeriod::query()->whereIn('employee_id', $employeeIds)->delete(),
                'employees' => Employee::query()->whereIn('id', $employeeIds)->forceDelete(),
                'students' => $this->deleteOnlyUnreferencedStudents($studentIds),
                'subjects' => $this->deleteOnlyUnreferencedSubjects($subjectIds),
                'groups' => $this->deleteOnlyUnreferencedGroups($groupIds),
                'teachers' => $this->deleteOnlyUnreferencedTeachers($teacherIds),
                'classrooms' => $this->deleteOnlyUnreferencedClassrooms(),
                'applications' => ApplicantApplication::query()->legacy()->whereIn('email', $applicationEmails)->delete(),
                'people' => $this->deleteOnlyUnreferencedPeople($personIds),
                'users' => User::query()->whereIn('email', $studentEmails->merge($teacherEmails)->values()->all())->delete(),
            ];

            return [
                'deleted' => $deleted,
                'skipped' => [
                    'students' => $studentIds->count() - $deleted['students'],
                    'subjects' => $subjectIds->count() - $deleted['subjects'],
                    'groups' => $groupIds->count() - $deleted['groups'],
                    'teachers' => $teacherIds->count() - $deleted['teachers'],
                ],
            ];
        });

        $result = [...$summary, 'summary' => $this->summary()];
        AuditLogService::log('demo_data', 'clear_demo', ['type' => 'demo_data', 'id' => null], null, $result, request());

        return response()->json([
            'message' => 'Демо-данные очищены. Администратор не удаляется.',
            'data' => $result,
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        abort_if(app()->environment('production'), Response::HTTP_FORBIDDEN, 'Сброс данных запрещен в production.');
        abort_unless(DB::getDriverName() === 'pgsql', Response::HTTP_UNPROCESSABLE_ENTITY, 'Полный сброс поддерживается только на PostgreSQL DEV-стенде.');

        $protectedTables = [
            'migrations', 'users', 'roles', 'permissions', 'permission_role', 'role_user', 'settings',
            'reference_catalogs', 'reference_items', 'cache', 'cache_locks', 'jobs', 'job_batches',
            'failed_jobs', 'password_reset_tokens', 'sessions',
        ];

        $summary = DB::transaction(function () use ($protectedTables): array {
            // Users are retained so the active administrator session and system access survive the reset.
            User::query()->whereNotNull('person_id')->update(['person_id' => null, 'person_type' => null]);
            $tables = collect(DB::select("select tablename from pg_tables where schemaname = 'public'"))
                ->map(fn (object $row) => $row->tablename)
                ->filter(fn (string $table) => ! in_array($table, $protectedTables, true))
                ->filter(fn (string $table) => preg_match('/^[a-z_]+$/', $table))
                ->values();

            if ($tables->isNotEmpty()) {
                $quoted = $tables->map(fn (string $table) => '"'.$table.'"')->implode(', ');
                DB::statement("TRUNCATE TABLE {$quoted} RESTART IDENTITY CASCADE");
            }

            return $this->summary();
        });

        AuditLogService::log('demo_data', 'reset_development_data', ['type' => 'development_data', 'id' => null], null, $summary, $request, $request->user());

        return response()->json([
            'message' => 'Рабочие данные DEV очищены. Системные настройки, справочники и учетные записи сохранены.',
            'data' => $summary,
        ]);
    }

    public function export(): StreamedResponse
    {
        $filename = 'demo-data-summary-'.now()->format('Ymd-His').'.csv';

        AuditLogService::log('demo_data', 'export', ['type' => 'demo_data', 'id' => null], null, ['filename' => $filename], request());

        return CsvExport::download($filename, ['entity', 'count'], function (callable $row): void {
            foreach ($this->summary() as $entity => $count) {
                $row([$entity, $count]);
            }
        });
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $data = [
            'filename' => $request->file('file')->getClientOriginalName(),
            'size' => $request->file('file')->getSize(),
        ];
        AuditLogService::log('demo_data', 'import', ['type' => 'demo_data', 'id' => null], null, $data, $request);

        return response()->json([
            'message' => 'Файл принят. Расширенный импорт демо-данных будет подключен после согласования формата.',
            'data' => $data,
        ]);
    }


    /**
     * Демонстрационные записи стираются насовсем, а не уходят в корзину:
     * корзина — про ошибочно заведённые рабочие карточки, и наполнять её
     * очисткой демо-набора незачем.
     */
    private function deleteOnlyUnreferencedStudents($studentIds): int
    {
        return Student::query()
            ->whereIn('id', $studentIds)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('graduates')->whereColumn('graduates.student_id', 'students.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('exam_results')->whereColumn('exam_results.student_id', 'students.id'))
            ->forceDelete();
    }

    private function deleteOnlyUnreferencedSubjects($subjectIds): int
    {
        return Subject::query()
            ->whereIn('id', $subjectIds)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('curriculum_items')->whereColumn('curriculum_items.subject_id', 'subjects.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('teaching_load_items')->whereColumn('teaching_load_items.subject_id', 'subjects.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('exams')->whereColumn('exams.subject_id', 'subjects.id'))
            ->forceDelete();
    }

    private function deleteOnlyUnreferencedGroups($groupIds): int
    {
        return Group::query()
            ->whereIn('id', $groupIds)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('students')->whereColumn('students.group_id', 'groups.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('teaching_load_items')->whereColumn('teaching_load_items.group_id', 'groups.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('exams')->whereColumn('exams.group_id', 'groups.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('graduates')->whereColumn('graduates.group_id', 'groups.id'))
            ->forceDelete();
    }

    private function deleteOnlyUnreferencedTeachers($teacherIds): int
    {
        return Teacher::query()
            ->whereIn('id', $teacherIds)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('groups')->whereColumn('groups.curator_id', 'teachers.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('schedule_lessons')->whereColumn('schedule_lessons.teacher_id', 'teachers.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('teaching_loads')->whereColumn('teaching_loads.teacher_id', 'teachers.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('exams')->whereColumn('exams.teacher_id', 'teachers.id'))
            ->forceDelete();
    }

    private function deleteOnlyUnreferencedClassrooms(): int
    {
        return Classroom::query()
            ->where('building', 'Демо-корпус')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('schedule_lessons')->whereColumn('schedule_lessons.classroom_id', 'classrooms.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('exams')->whereColumn('exams.classroom_id', 'classrooms.id'))
            ->delete();
    }

    private function deleteOnlyUnreferencedPeople($personIds): int
    {
        return Person::query()
            ->whereIn('id', $personIds)
            ->where(function ($query): void {
                $query
                    ->where('email', 'student@local')
                    ->orWhere('email', 'teacher@local')
                    ->orWhere('email', 'like', 'student.demo.%@demo.college.local')
                    ->orWhere('email', 'like', 'teacher.demo.%@demo.college.local');
            })
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('students')->whereColumn('students.person_id', 'people.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('teachers')->whereColumn('teachers.person_id', 'people.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('employees')->whereColumn('employees.person_id', 'people.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('applicant_applications')->whereColumn('applicant_applications.person_id', 'people.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('graduates')->whereColumn('graduates.person_id', 'people.id'))
            ->delete();
    }

    private function summary(): array
    {
        return [
            'people' => Person::query()->count(),
            'students' => Student::query()->count(),
            'employees' => Employee::query()->count(),
            'groups' => Group::query()->count(),
            'teachers' => Teacher::query()->count(),
            'subjects' => Subject::query()->count(),
            'classrooms' => Classroom::query()->count(),
            'schedule_lessons' => ScheduleLesson::query()->count(),
            'attendance' => Attendance::query()->count(),
            'grades' => Grade::query()->count(),
            'access_events' => AccessEvent::query()->count(),
            'applicant_applications' => ApplicantApplication::query()->legacy()->count(),
        ];
    }
}
