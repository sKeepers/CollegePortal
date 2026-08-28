<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentGradeResource;
use App\Models\JournalGrade;
use App\Models\JournalLesson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Support\Http\PageSize;

/**
 * Оценки студента списком: карточка студента и его кабинет.
 *
 * **Читает журнал.** До 16.08.2026 здесь жила старая таблица `grades` со своим
 * CRUD, и это был второй источник правды: журнал писал в `journal_grades`, а
 * карточка студента показывала `grades`, куда с июля не приходило ни одной
 * живой оценки. Владелец решил 16.08.2026 не откладывать сведение — реальных
 * данных в старой таблице не было, только демонстрационные.
 *
 * **Записи здесь больше нет намеренно.** Оценка ставится в журнале, где у неё
 * есть занятие, автор, вес и подпись; второй путь записи означал бы, что
 * половина оценок снова окажется вне журнала. Маршруты `POST/PUT/DELETE
 * api/grades` сняты, и ни один экран их не звал — проверено поиском по
 * `frontend/src`.
 */
class GradeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $grades = JournalGrade::query()
            ->with(['journalLesson.subject', 'journalLesson.teacher', 'gradeType', 'student'])
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->when($request->integer('student_id'), fn (Builder $query, int $id) => $query->where('student_id', $id))
            ->when($request->integer('journal_lesson_id'), fn (Builder $query, int $id) => $query->where('journal_lesson_id', $id))
            // Старый параметр продолжает работать: у занятия журнала сохранена
            // ссылка на строку расписания, из которой его открыли.
            ->when($request->integer('schedule_lesson_id'), fn (Builder $query, int $id) => $query->whereIn(
                'journal_lesson_id',
                JournalLesson::query()->where('legacy_schedule_lesson_id', $id)->select('id'),
            ))
            ->latest('marked_at')
            ->latest('id')
            ->paginate(PageSize::from($request, 20));

        return StudentGradeResource::collection($grades);
    }
}
