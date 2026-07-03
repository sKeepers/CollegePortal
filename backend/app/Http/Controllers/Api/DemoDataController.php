<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApplicantApplication;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Grade;
use App\Models\Group;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
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

    public function create(): JsonResponse
    {
        Artisan::call('db:seed', [
            '--class' => DemoDataSeeder::class,
            '--force' => true,
        ]);

        return response()->json([
            'message' => 'Демо-данные созданы или обновлены.',
            'data' => $this->summary(),
        ]);
    }

    public function clear(): JsonResponse
    {
        abort_if(app()->environment('production'), Response::HTTP_FORBIDDEN, 'Очистка демо-данных запрещена в production.');

        $summary = DB::transaction(function (): array {
            $studentEmails = ['student@college-portal.local', 'student2@college-portal.local'];
            $teacherEmails = ['teacher@college-portal.local'];
            $applicationEmails = ['anohin@example.test', 'borisova@example.test', 'kazachenko@example.test'];

            $studentIds = Student::query()->whereIn('email', $studentEmails)->pluck('id');
            $teacherIds = Teacher::query()->whereIn('email', $teacherEmails)->pluck('id');
            $groupIds = Group::query()->whereIn('name', ['ИСП-101', 'M-101'])->pluck('id');
            $subjectIds = Subject::query()->whereIn('code', ['MUS-101'])->pluck('id');
            $lessonIds = ScheduleLesson::query()
                ->whereIn('group_id', $groupIds)
                ->orWhereIn('teacher_id', $teacherIds)
                ->orWhereIn('subject_id', $subjectIds)
                ->pluck('id');

            $deleted = [
                'grades' => Grade::query()->whereIn('schedule_lesson_id', $lessonIds)->delete(),
                'attendance' => Attendance::query()->whereIn('schedule_lesson_id', $lessonIds)->delete(),
                'schedule_lessons' => ScheduleLesson::query()->whereIn('id', $lessonIds)->delete(),
                'students' => $this->deleteOnlyUnreferencedStudents($studentIds),
                'subjects' => $this->deleteOnlyUnreferencedSubjects($subjectIds),
                'groups' => $this->deleteOnlyUnreferencedGroups($groupIds),
                'teachers' => $this->deleteOnlyUnreferencedTeachers($teacherIds),
                'classrooms' => $this->deleteOnlyUnreferencedClassrooms(),
                'applications' => ApplicantApplication::query()->whereIn('email', $applicationEmails)->delete(),
                'users' => User::query()->whereIn('email', [...$studentEmails, ...$teacherEmails])->delete(),
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

        return response()->json([
            'message' => 'Демо-данные очищены. Администратор не удаляется.',
            'data' => [...$summary, 'summary' => $this->summary()],
        ]);
    }

    public function export(): StreamedResponse
    {
        $filename = 'demo-data-summary-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function (): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['entity', 'count'], ';');
            foreach ($this->summary() as $entity => $count) {
                fputcsv($output, [$entity, $count], ';');
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        return response()->json([
            'message' => 'Файл принят. Расширенный импорт демо-данных будет подключен после согласования формата.',
            'data' => [
                'filename' => $request->file('file')->getClientOriginalName(),
                'size' => $request->file('file')->getSize(),
            ],
        ]);
    }


    private function deleteOnlyUnreferencedStudents($studentIds): int
    {
        return Student::query()
            ->whereIn('id', $studentIds)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('graduates')->whereColumn('graduates.student_id', 'students.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('exam_results')->whereColumn('exam_results.student_id', 'students.id'))
            ->delete();
    }

    private function deleteOnlyUnreferencedSubjects($subjectIds): int
    {
        return Subject::query()
            ->whereIn('id', $subjectIds)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('curriculum_items')->whereColumn('curriculum_items.subject_id', 'subjects.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('teaching_load_items')->whereColumn('teaching_load_items.subject_id', 'subjects.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('exams')->whereColumn('exams.subject_id', 'subjects.id'))
            ->delete();
    }

    private function deleteOnlyUnreferencedGroups($groupIds): int
    {
        return Group::query()
            ->whereIn('id', $groupIds)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('students')->whereColumn('students.group_id', 'groups.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('teaching_load_items')->whereColumn('teaching_load_items.group_id', 'groups.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('exams')->whereColumn('exams.group_id', 'groups.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('graduates')->whereColumn('graduates.group_id', 'groups.id'))
            ->delete();
    }

    private function deleteOnlyUnreferencedTeachers($teacherIds): int
    {
        return Teacher::query()
            ->whereIn('id', $teacherIds)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('groups')->whereColumn('groups.curator_id', 'teachers.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('schedule_lessons')->whereColumn('schedule_lessons.teacher_id', 'teachers.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('teaching_loads')->whereColumn('teaching_loads.teacher_id', 'teachers.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('exams')->whereColumn('exams.teacher_id', 'teachers.id'))
            ->delete();
    }

    private function deleteOnlyUnreferencedClassrooms(): int
    {
        return Classroom::query()
            ->where('number', '201')
            ->where('building', 'Главный корпус')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('schedule_lessons')->whereColumn('schedule_lessons.classroom_id', 'classrooms.id'))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('exams')->whereColumn('exams.classroom_id', 'classrooms.id'))
            ->delete();
    }

    private function summary(): array
    {
        return [
            'students' => Student::query()->count(),
            'groups' => Group::query()->count(),
            'teachers' => Teacher::query()->count(),
            'subjects' => Subject::query()->count(),
            'classrooms' => Classroom::query()->count(),
            'applicant_applications' => ApplicantApplication::query()->count(),
        ];
    }
}
