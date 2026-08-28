<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentAttendanceResource;
use App\Models\JournalAttendance;
use App\Models\JournalLesson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Support\Http\PageSize;

/**
 * Отметки посещаемости студента списком: карточка студента и его кабинет.
 *
 * **Читает журнал** — `journal_attendance`. Старая таблица `attendance` со
 * своим CRUD была вторым источником правды: преподаватель отмечал в журнале, а
 * карточка студента показывала таблицу, куда с июля не приходило ни одной живой
 * отметки. Сведено 16.08.2026 решением владельца.
 *
 * Строки с `source = roster` отброшены: их создаёт сам журнал при открытии
 * занятия, до того как преподаватель кого-либо отметил. Показывать их значило
 * бы утверждать, что человек присутствовал на занятии, которое ещё не вели.
 *
 * Записи здесь больше нет: отметку ставят в журнале.
 */
class AttendanceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $attendance = JournalAttendance::query()
            ->with(['journalLesson.subject', 'journalLesson.teacher', 'student'])
            ->where('source', '!=', 'roster')
            ->when($request->integer('student_id'), fn (Builder $query, int $id) => $query->where('student_id', $id))
            ->when($request->integer('journal_lesson_id'), fn (Builder $query, int $id) => $query->where('journal_lesson_id', $id))
            ->when($request->string('status')->toString(), fn (Builder $query, string $status) => $query->where('status', $status))
            // Старый параметр продолжает работать через ссылку занятия журнала
            // на строку расписания, из которой оно открыто.
            ->when($request->integer('schedule_lesson_id'), fn (Builder $query, int $id) => $query->whereIn(
                'journal_lesson_id',
                JournalLesson::query()->where('legacy_schedule_lesson_id', $id)->select('id'),
            ))
            ->latest('marked_at')
            ->latest('id')
            ->paginate(PageSize::from($request, 20));

        return StudentAttendanceResource::collection($attendance);
    }
}
