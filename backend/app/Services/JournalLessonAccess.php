<?php

namespace App\Services;

use App\Models\JournalLesson;
use App\Models\ScheduleLesson;
use App\Models\Student;
use App\Models\User;

/**
 * Кто что может сделать с одним занятием журнала.
 *
 * Правило вынесено из контроллера, потому что его спрашивают двое: сам
 * контроллер — чтобы отказать, и `JournalLessonResource` — чтобы экран не
 * показывал кнопку, которая ответит отказом. Посчитанное в двух местах, оно
 * однажды разойдётся, и разойдётся в худшую сторону: кнопка есть, нажатие
 * даёт `403`, а виноватым выглядит журнал.
 *
 * Чтение и правка разведены намеренно. Куратор своей группы читает занятие
 * любого преподавателя — это решение владельца от 12.08.2026, — но правит
 * только своё. Признак `$curatorMayRead` включается лишь там, где куратору
 * действительно нужно посмотреть: карточка занятия, вложение, выгрузка.
 */
class JournalLessonAccess
{
    public function __construct(private readonly CuratorScopeService $curatorScope)
    {
    }

    /** Может ли человек менять это занятие. */
    public function canEdit(User $user, JournalLesson $lesson): bool
    {
        if ($user->hasRole('admin') || $user->hasPermission('journal.view_all')) {
            return true;
        }

        return $this->curatorScope->teacherIds($user)->contains((int) $lesson->teacher_id);
    }

    /**
     * Может ли человек видеть данные журнала по целой группе — оценки и
     * посещаемость в отчётах.
     *
     * Своя группа — это две разные связи, и обе настоящие: куратор отвечает за
     * группу, преподаватель ведёт в ней занятия. Отрезать вторую нельзя —
     * преподаватель строит отчёт по группе, которой преподаёт, и это его
     * ежедневная работа; открытой оставалась и третья возможность — любая
     * группа колледжа любому, у кого есть `journal.view`, и вот её здесь и
     * закрывают.
     *
     * Ведение ищется и в журнале, и в расписании: занятие могло не дойти до
     * журнала, а отчёт по нему всё равно строится.
     */
    public function canReadGroup(User $user, int $groupId): bool
    {
        if ($user->hasRole('admin') || $user->hasPermission('journal.view_all')) {
            return true;
        }

        if ($this->curatorScope->curates($user, $groupId)) {
            return true;
        }

        $teacherIds = $this->curatorScope->teacherIds($user);

        if ($teacherIds->isEmpty()) {
            return false;
        }

        return JournalLesson::query()
            ->where('group_id', $groupId)
            ->whereIn('teacher_id', $teacherIds->all())
            ->exists()
            || ScheduleLesson::query()
                ->where('group_id', $groupId)
                ->whereIn('teacher_id', $teacherIds->all())
                ->exists();
    }

    /** Может ли человек видеть это занятие. */
    public function canRead(User $user, JournalLesson $lesson, bool $curatorMayRead = false): bool
    {
        if ($this->canEdit($user, $lesson)) {
            return true;
        }

        if ($curatorMayRead && $this->curatorScope->curates($user, (int) $lesson->group_id)) {
            return true;
        }

        // Студент видит занятие своей группы: это правило было здесь до
        // куратора и остаётся неизменным.
        $student = Student::query()->where('user_id', $user->id)->first();

        return $student !== null && (int) $student->group_id === (int) $lesson->group_id;
    }
}
