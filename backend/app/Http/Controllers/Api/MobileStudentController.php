<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DigitalIdentityResource;
use App\Http\Resources\ScheduleLessonResource;
use App\Http\Resources\StudentAttendanceResource;
use App\Http\Resources\StudentGradeResource;
use App\Http\Resources\StudentResource;
use App\Models\DigitalIdentity;
use App\Models\DormPayment;
use App\Models\DormPlacement;
use App\Models\JournalAttendance;
use App\Models\JournalGrade;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Services\QrSvgService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Кабинет студента: расписание на день, свои оценки и отметки, QR-пропуск.
 *
 * Оценки и посещаемость читаются из журнала (`journal_grades`,
 * `journal_attendance`) — оттуда, куда их ставит преподаватель. До 16.08.2026
 * кабинет читал старые таблицы, в которые с июля не приходило ни одной живой
 * записи, и студент не видел ни одной своей оценки; на стенде это было не
 * видно, потому что демонстрационный набор наполнял обе пары сразу.
 */
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
                    'dorm' => null,
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

        $grades = JournalGrade::query()
            ->with(['journalLesson.subject', 'journalLesson.teacher', 'gradeType'])
            ->where('student_id', $student->id)
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->latest('marked_at')
            ->latest('id')
            ->limit(8)
            ->get();

        $attendance = JournalAttendance::query()
            ->with(['journalLesson.subject', 'journalLesson.teacher'])
            ->where('student_id', $student->id)
            // Заготовки, созданные журналом при открытии занятия, не отметки:
            // студент не должен видеть «присутствовал» до того, как его
            // отметили.
            ->where('source', '!=', 'roster')
            ->latest('marked_at')
            ->latest('id')
            ->limit(8)
            ->get();

        $attendanceSummary = JournalAttendance::query()
            ->where('student_id', $student->id)
            ->where('source', '!=', 'roster')
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
                'grades' => StudentGradeResource::collection($grades),
                'attendance' => StudentAttendanceResource::collection($attendance),
                'attendance_summary' => [
                    'present' => (int) ($attendanceSummary['present'] ?? 0),
                    'absent' => (int) ($attendanceSummary['absent'] ?? 0),
                    'late' => (int) ($attendanceSummary['late'] ?? 0),
                    'excused' => (int) ($attendanceSummary['excused'] ?? 0),
                    'sick' => (int) ($attendanceSummary['sick'] ?? 0),
                    'remote' => (int) ($attendanceSummary['remote'] ?? 0),
                ],
                'digital_identity' => $digitalIdentity ? new DigitalIdentityResource($digitalIdentity) : null,
                'qr_svg' => $dynamicQr ? $qrSvgService->renderSvg($dynamicQr['payload']) : null,
                'qr_expires_at' => $dynamicQr ? $dynamicQr['expires_at']->toIso8601String() : null,
                'qr_refresh_seconds' => QrSvgService::DYNAMIC_TTL_SECONDS,
                'dorm' => $this->dorm($student),
                'notifications' => $this->mockNotifications(),
            ],
        ];
    }

    /**
     * Общежитие глазами самого проживающего: свой блок и своя оплата.
     *
     * Решение владельца 01.09.2026: «студенту не нужно показывать соседей, он
     * должен видеть только своё». Поэтому здесь **нет ни фамилий, ни числа
     * проживающих, ни свободных мест** — и это ограничение стоит именно в
     * ответе, а не на экране: убрать соседей из разметки легко, но пришедшие в
     * ответе данные видит всякий, кто откроет ответ. Занятость не отдаётся по
     * той же причине: из «в блоке 6 мест, занято 4» следует, что рядом трое.
     *
     * Вместимость отдаётся: она говорит, каков блок, но не кто в нём.
     *
     * Ни нового права, ни нового пути здесь не нужно. Ручка и так берёт
     * студента текущего пользователя, то есть «только своё» обеспечено её
     * устройством, а `/student` уже стоит в списке путей роли.
     *
     * Возвращает null, если действующего заселения нет: у 574 студентов из 596
     * его не будет, и раздел им не показывается вовсе.
     */
    private function dorm(Student $student): ?array
    {
        $placement = DormPlacement::query()
            ->with('room')
            ->where('student_id', $student->id)
            ->whereNull('moved_out_at')
            ->latest('moved_in_at')
            ->first();

        if ($placement === null || $placement->room === null) {
            return null;
        }

        // Замещённые отметки не показываем: строка из 1С побеждает ручную за тот
        // же период, и показать обе значило бы показать оплату дважды.
        $payments = DormPayment::query()
            ->where('student_id', $student->id)
            ->whereNull('superseded_by_id')
            ->orderByDesc('paid_through')
            ->get();

        return [
            // Одна строка комнаты в портале — это блок целиком: в нём две жилые
            // комнаты, кухня и санузел. Подпись «блок» здесь не украшение —
            // студент ищет свою дверь по номеру блока.
            'room' => [
                'number' => $placement->room->number,
                'floor' => $placement->room->floor,
                'capacity' => $placement->room->capacity,
            ],
            'moved_in_at' => $placement->moved_in_at?->toDateString(),
            // «Оплачено по такое-то число» — единственное, что база знает про
            // оплату. Задолженности в ней нет ни полем, ни суммой к оплате,
            // поэтому её здесь не считают и не показывают: «задолженность 0»
            // при отсутствии учёта — это не ноль, а неизвестность.
            'paid_through' => $payments->max('paid_through')?->toDateString(),
            'payments' => $payments->map(fn (DormPayment $payment): array => [
                'id' => $payment->id,
                'paid_through' => $payment->paid_through?->toDateString(),
                'amount' => $payment->amount,
                'paid_at' => $payment->paid_at?->toDateString(),
                'origin' => $payment->origin,
            ])->all(),
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
