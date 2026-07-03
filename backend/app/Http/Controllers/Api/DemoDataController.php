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
                'students' => Student::query()->whereIn('id', $studentIds)->delete(),
                'subjects' => Subject::query()->whereIn('id', $subjectIds)->delete(),
                'groups' => Group::query()->whereIn('id', $groupIds)->delete(),
                'teachers' => Teacher::query()->whereIn('id', $teacherIds)->delete(),
                'classrooms' => Classroom::query()->where('number', '201')->where('building', 'Главный корпус')->delete(),
                'applications' => ApplicantApplication::query()->whereIn('email', $applicationEmails)->delete(),
                'users' => User::query()->whereIn('email', [...$studentEmails, ...$teacherEmails])->delete(),
            ];

            return $deleted;
        });

        return response()->json([
            'message' => 'Демо-данные очищены. Администратор не удаляется.',
            'data' => ['deleted' => $summary, 'summary' => $this->summary()],
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
