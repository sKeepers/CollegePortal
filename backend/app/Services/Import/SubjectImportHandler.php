<?php

namespace App\Services\Import;

use App\Models\Subject;
use App\Services\AutoCodeService;
use App\Services\Import\Concerns\ResolvesImportRelations;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Загрузка дисциплин.
 *
 * Колонка «Преподаватели» добавлена 10.08.2026: выгрузка дисциплин отдавала
 * привязку преподавателей, а обратная загрузка её теряла — такого поля в
 * шаблоне не было вовсе. Дисциплина без преподавателей не попадает ни в
 * нагрузку, ни в расписание, поэтому терялось не оформление, а связь.
 */
class SubjectImportHandler extends AbstractImportHandler
{
    use ResolvesImportRelations;

    public function __construct(private readonly AutoCodeService $autoCodeService)
    {
    }

    public function type(): string { return 'subjects'; }
    public function label(): string { return 'Дисциплины'; }
    public function modelClass(): string { return Subject::class; }
    public function keyFields(): array { return ['code']; }

    public function fields(): array
    {
        return [
            // Ярлык — «Дисциплина», как в шапке шаблона: под двумя именами одна колонка
            // останавливает того, кто сверяет файл со справкой. «Название» осталось
            // синонимом — файлы, заполненные раньше, читаются по-прежнему.
            'name' => ['label' => 'Дисциплина', 'required' => true, 'aliases' => ['дисциплина', 'название', 'name']],
            'code' => ['label' => 'Код', 'required' => false, 'aliases' => ['код', 'code']],
            'department' => ['label' => 'Отделение', 'required' => false, 'aliases' => ['отделение', 'кафедра', 'department']],
            'description' => ['label' => 'Описание', 'required' => false, 'aliases' => ['описание', 'description']],
            'teachers' => ['label' => 'Преподаватели', 'required' => false, 'aliases' => ['преподаватели', 'преподаватель', 'teachers', 'teacher_ids']],
        ];
    }

    public function templateHeaders(): array
    {
        return ['Дисциплина', 'Код', 'Отделение', 'Описание', 'Преподаватели'];
    }

    public function templateExample(): array
    {
        return ['История музыки', 'MUS-101', 'Музыкальное отделение', 'Базовая дисциплина', 'Петрова Анна Викторовна | Смирнова Елена Викторовна'];
    }

    public function prepare(array $data): array
    {
        // Код здесь НЕ проставляется, и это главное в методе. `findExisting`
        // вызывается после `prepare`, а автокод — всегда новый: поиск по нему не
        // находил ничего, и запасной поиск по названию, стоящий в `findExisting`
        // строкой ниже, не выполнялся ни разу. Режим «пропускать дубли» при этом
        // обещал защиту, которой не было: 28.08.2026 повторная загрузка того же
        // файла дала «создано 140, пропущено 0» и задвоила одиннадцать дисциплин
        // в рабочей базе стенда.
        //
        // Код нужен только новой дисциплине, поэтому он и получается при создании —
        // в `import()`, когда уже известно, что existing нет.
        //
        // Пустая клетка приводится к «нет значения»: пустая строка пережила бы фильтр
        // в `payload()` и стёрла бы код у существующей дисциплины при обновлении.
        $data['code'] = ($data['code'] ?? null) ?: null;

        $resolved = $this->resolveTeacherIdList($data['teachers'] ?? null);
        $data['teacher_ids'] = $resolved['ids'];
        $data['teachers_unresolved'] = $resolved['unresolved'];

        return $data;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'teachers' => ['nullable', 'string'],
        ];
    }

    public function findExisting(array $data): ?Model
    {
        return ! empty($data['code'])
            ? Subject::where('code', $data['code'])->first()
            : Subject::where('name', $data['name'] ?? null)->first();
    }

    /**
     * Разбор колонки «Преподаватели» отдельным входом: тем же путём её читает
     * собственный CSV-импорт дисциплин. Две точки загрузки на одном файле
     * обязаны давать один результат, а не расходиться в том, кого они нашли.
     *
     * @return array{ids: array<int, int>, unresolved: array<int, string>}
     */
    public function teachersFromColumn(?string $value): array
    {
        return $this->resolveTeacherIdList($value);
    }

    /**
     * Ненайденный преподаватель — **замечание, а не ошибка строки**.
     *
     * Раньше он её останавливал: опечатка в одной фамилии из списка — и дисциплины в
     * портале нет вовсе. Первого сентября это расписание, которое не на что поставить,
     * из-за буквы в чужой фамилии.
     *
     * Теперь дисциплина загружается с теми преподавателями, которые нашлись, а
     * ненайденные названы поимённо. Однофамильцы попадают сюда же: имя, под которым
     * нашлось несколько человек, требует уточнения, а не выбора за пользователя.
     */
    public function rowNotices(array $data): array
    {
        $unresolved = $data['teachers_unresolved'] ?? [];

        if ($unresolved === []) {
            return [];
        }

        return ['teachers' => array_map(
            static fn (string $name): string => "Преподаватель не найден однозначно: {$name}. Дисциплина загружена без него; уточните ФИО полностью или укажите идентификатор.",
            $unresolved
        )];
    }

    public function import(array $data, string $mode): string
    {
        $existing = $this->findExisting($data);

        if ($mode === self::MODE_UPDATE) {
            if (! $existing) {
                return 'skipped';
            }

            $existing->update($this->payload($data, true));
            $this->syncTeachers($existing, $data);

            return 'updated';
        }

        if ($existing) {
            if ($mode === self::MODE_SKIP_DUPLICATES) {
                return 'skipped';
            }

            throw new RuntimeException('Дубликат по ключевому полю.');
        }

        // Код получает только новая дисциплина: у существующей он свой и меняться
        // от повторной загрузки не должен.
        $data['code'] = ($data['code'] ?? null) ?: $this->autoCodeService->subjectCode($data['name'] ?? null);

        $subject = Subject::create($this->payload($data));
        $this->syncTeachers($subject, $data);

        return 'created';
    }

    /**
     * Колонки в файле не было — связь не трогаем: файл без неё не должен молча
     * отвязывать всех преподавателей. Колонка есть и пуста — это осознанная
     * очистка, человек видит пустую ячейку и знает, что стирает.
     */
    public function syncTeachers(Subject $subject, array $data): void
    {
        if (($data['teachers'] ?? null) === null) {
            return;
        }

        $subject->teachers()->sync($data['teacher_ids'] ?? []);
    }

    protected function virtualFields(): array
    {
        // Преподаватели — связь, а не колонка дисциплины: записывается после
        // сохранения через syncTeachers.
        return ['teachers'];
    }
}
