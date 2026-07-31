<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Http\Resources\DigitalIdentityResource;
use App\Http\Resources\GradeResource;
use App\Http\Resources\ScheduleLessonResource;
use App\Http\Resources\StudentResource;
use App\Models\Attendance;
use App\Models\DigitalIdentity;
use App\Models\Grade;
use App\Models\ScheduleLesson;
use App\Services\QrSvgService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MobileStudentController extends Controller
{
    public function show(Request $request, QrSvgService $qrSvgService): array
    {
        $student = $request->user()
            ->student()
            ->with(['group.educationProgram'])
            ->first();

        if ($student === null) {
            return [
                'data' => [
                    'student' => null,
                    'message' => 'Текущий пользователь не связан с карточкой студента.',
                    'today_schedule' => [],
                    'next_lesson' => null,
                    'grades' => [],
                    'attendance' => [],
                    'attendance_summary' => ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0],
                    'digital_identity' => null,
                    'qr_svg' => null,
                    'qr_expires_at' => null,
                    'qr_refresh_seconds' => QrSvgService::DYNAMIC_TTL_SECONDS,
                    'notifications' => $this->mockNotifications(),
                ],
            ];
        }

        $scheduleDate = $this->scheduleDate($request);
        $schedule = ScheduleLesson::query()
            ->with(['group', 'teacher', 'subject', 'classroom'])
            ->where('group_id', $student->group_id)
            ->whereDate('lesson_date', $scheduleDate)
            ->orderBy('starts_at')
            ->get();

        $now = Carbon::now();
        $nextLesson = $scheduleDate->isToday()
            ? $schedule->first(function (ScheduleLesson $lesson) use ($now): bool {
                return $lesson->starts_at !== null && Carbon::parse($lesson->lesson_date->toDateString().' '.$lesson->starts_at->format('H:i'))->greaterThanOrEqualTo($now);
            }) ?? $schedule->first()
            : $schedule->first();

        $grades = Grade::query()
            ->with(['scheduleLesson.subject', 'scheduleLesson.teacher', 'scheduleLesson.group', 'scheduleLesson.classroom'])
            ->where('student_id', $student->id)
            ->latest('id')
            ->limit(8)
            ->get();

        $attendance = Attendance::query()
            ->with(['scheduleLesson.subject', 'scheduleLesson.teacher', 'scheduleLesson.group', 'scheduleLesson.classroom'])
            ->where('student_id', $student->id)
            ->latest('id')
            ->limit(8)
            ->get();

        $attendanceSummary = Attendance::query()
            ->where('student_id', $student->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $digitalIdentity = DigitalIdentity::query()
            ->where('entity_type', DigitalIdentity::ENTITY_STUDENT)
            ->where('entity_id', $student->id)
            ->where('status', DigitalIdentity::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>=', now());
            })
            ->latest('issued_at')
            ->first();

        $dynamicQr = $digitalIdentity ? $qrSvgService->dynamicPayload($digitalIdentity) : null;

        return [
            'data' => [
                'student' => new StudentResource($student),
                'schedule_date' => $scheduleDate->toDateString(),
                'today_schedule' => ScheduleLessonResource::collection($schedule),
                'next_lesson' => $nextLesson ? new ScheduleLessonResource($nextLesson) : null,
                'grades' => GradeResource::collection($grades),
                'attendance' => AttendanceResource::collection($attendance),
                'attendance_summary' => [
                    'present' => (int) ($attendanceSummary['present'] ?? 0),
                    'absent' => (int) ($attendanceSummary['absent'] ?? 0),
                    'late' => (int) ($attendanceSummary['late'] ?? 0),
                    'excused' => (int) ($attendanceSummary['excused'] ?? 0),
                ],
                'digital_identity' => $digitalIdentity ? new DigitalIdentityResource($digitalIdentity) : null,
                'qr_svg' => $dynamicQr ? $qrSvgService->renderSvg($dynamicQr['payload']) : null,
                'qr_expires_at' => $dynamicQr ? $dynamicQr['expires_at']->toIso8601String() : null,
                'qr_refresh_seconds' => QrSvgService::DYNAMIC_TTL_SECONDS,
                'notifications' => $this->mockNotifications(),
            ],
        ];
    }

    private function scheduleDate(Request $request): Carbon
    {
        $value = $request->query('date');

        if ($value === null || $value === '') {
            return Carbon::today();
        }

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            abort(422, 'Дата расписания должна быть передана в формате ГГГГ-ММ-ДД.');
        }

        try {
            return Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Exception) {
            abort(422, 'Дата расписания некорректна.');
        }
    }

    private function mockNotifications(): array
    {
        return [
            ['id' => 1, 'title' => 'Напоминание', 'text' => 'Проверьте расписание занятий на сегодня.', 'tone' => 'info'],
            ['id' => 2, 'title' => 'Учебная часть', 'text' => 'Уведомления будут подключены на следующем этапе.', 'tone' => 'neutral'],
        ];
    }
}
