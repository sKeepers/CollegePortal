<?php

namespace App\Services\Import\Concerns;

use App\Models\Classroom;
use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Support\Collection;

trait ResolvesImportRelations
{
    protected function resolveGroupId($id, ?string $name): ?int
    {
        if ($id) { return (int) $id; }
        return $name ? Group::where('name', $name)->value('id') : null;
    }

    protected function resolveProgramId($id, ?string $name): ?int
    {
        if ($id) { return (int) $id; }
        return $name ? EducationProgram::where('name', $name)->value('id') : null;
    }

    protected function resolveSubjectId($id, ?string $code, ?string $name): ?int
    {
        if ($id) { return (int) $id; }
        if ($code) { return Subject::where('code', $code)->value('id'); }
        return $name ? Subject::where('name', $name)->value('id') : null;
    }

    protected function resolveTeacherId($id, ?string $name): ?int
    {
        if ($id) { return (int) $id; }
        $name = trim((string) $name);
        if ($name === '') { return null; }
        return Teacher::query()
            ->whereRaw("trim(concat_ws(' ', last_name, first_name, middle_name)) = ?", [$name])
            ->orWhereRaw("trim(concat_ws(' ', last_name, first_name)) = ?", [$name])
            ->value('id');
    }

    /**
     * Список преподавателей одной колонкой: «Фамилия Имя Отчество | Фамилия Имя».
     * Числовое значение считается идентификатором — файл с машинными колонками
     * грузится в ту же колонку и не требует второго шаблона.
     *
     * Имя, под которым нашлось несколько преподавателей, не связывается ни с
     * кем: тихая привязка к первому попавшемуся однофамильцу — это неверные
     * данные, о которых никто не узнает. Вызывающий превращает `unresolved`
     * в ошибку строки.
     *
     * @return array{ids: array<int, int>, unresolved: array<int, string>}
     */
    protected function resolveTeacherIdList(?string $value): array
    {
        $ids = [];
        $unresolved = [];

        foreach (preg_split('/\s*[|,]\s*/u', (string) $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            if (ctype_digit($part)) {
                $ids[] = (int) $part;
                continue;
            }

            $matches = Teacher::query()
                ->whereRaw("trim(concat_ws(' ', last_name, first_name, middle_name)) = ?", [$part])
                ->orWhereRaw("trim(concat_ws(' ', last_name, first_name)) = ?", [$part])
                ->pluck('id');

            if ($matches->count() === 1) {
                $ids[] = (int) $matches->first();
                continue;
            }

            $unresolved[] = $part;
        }

        return ['ids' => array_values(array_unique($ids)), 'unresolved' => $unresolved];
    }

    /**
     * Номер аудитории есть в нескольких корпусах — выбирать наугад нельзя.
     *
     * Возвращается вместо идентификатора, чтобы вызывающий отличил спор от
     * «не нашли»: до 30.08.2026 оба случая давали одно и то же, и спор
     * разрешался первой попавшейся строкой.
     */
    public const CLASSROOM_AMBIGUOUS = -2;

    /** Аудитории, прочитанные один раз на загрузку: за неё они не меняются. */
    private ?Collection $classroomsForMatching = null;

    /**
     * Аудитория по номеру и корпусу.
     *
     * До 30.08.2026 корпус участвовал в отборе, **только если был задан**, а
     * итог брался через `value('id')` — то есть при двух аудиториях «101» и
     * пустом корпусе возвращалась первая по порядку, молча. Пока корпус в
     * портале один, промаха нет физически; он появляется в тот день, когда
     * корпусов становится два, — 30.08.2026 приходят Голенева и Серова.
     *
     * Отбор по корпусу оставлен условным намеренно: файл без колонки «Корпус»
     * обязан грузиться как раньше, пока номер однозначен. Изменилось только
     * поведение при споре: раньше выбор, теперь отказ.
     */
    protected function resolveClassroomId($id, ?string $number, ?string $building): ?int
    {
        if ($id) { return (int) $id; }
        if (!$number) { return null; }

        $wantedNumber = $this->comparableRoomName($number);
        $wantedBuilding = $this->comparableRoomName($building);

        $found = $this->classroomsForMatching()
            ->filter(function (Classroom $classroom) use ($wantedNumber, $wantedBuilding): bool {
                if ($this->comparableRoomName($classroom->number) !== $wantedNumber) { return false; }

                return $wantedBuilding === '' || $this->comparableRoomName($classroom->building) === $wantedBuilding;
            })
            ->pluck('id');

        if ($found->count() > 1) { return self::CLASSROOM_AMBIGUOUS; }

        return $found->first();
    }

    /**
     * Имя аудитории в сравнимом виде: лишние пробелы убраны, регистр снят.
     *
     * До 01.09.2026 сравнение было точным, и пока аудитории звались числами, этого
     * хватало. 01.09 у колледжа появились залы — «Большой зал» на Голеневой и
     * «Концертный зал» на Крупской, — а имя зала завуч набирает руками: «Большой  зал»
     * с двумя пробелами и «большой зал» со строчной для человека одно и то же, а для
     * точного сравнения — разное, и строка отказывала бы «аудитория не найдена».
     *
     * **Синонимы не сводятся намеренно.** «БЗ» и «Большой зал» портал считать одним не
     * должен: это уже угадывание, а угаданная аудитория ставит группу не туда молча.
     * Приводится только запись одного и того же имени, не разные имена.
     *
     * Сравнение делается в PHP, а не в SQL, и это не прихоть: `lower()` в SQLite не
     * знает кириллицы — на этом 01.09.2026 ствол простоял красным, пока причину не
     * нашли. Строк здесь сто с небольшим, цена перебора незаметна.
     */
    private function comparableRoomName(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value)));
    }

    /**
     * Аудитории для сравнения, прочитанные один раз на загрузку.
     *
     * Файл расписания — это тысячи строк, и запрашивать сотню аудиторий на каждую
     * незачем: за одну загрузку они не меняются.
     *
     * @return \Illuminate\Support\Collection<int, Classroom>
     */
    private function classroomsForMatching(): Collection
    {
        return $this->classroomsForMatching ??= Classroom::query()->get(['id', 'number', 'building']);
    }
}
