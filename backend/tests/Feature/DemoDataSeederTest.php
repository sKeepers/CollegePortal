<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Attendance;
use App\Models\Curriculum;
use App\Models\Diploma;
use App\Models\DiplomaSupplement;
use App\Models\Employee;
use App\Models\Graduate;
use App\Models\Group;
use App\Models\JournalAttendance;
use App\Models\JournalGrade;
use App\Models\JournalLesson;
use App\Models\Person;
use App\Models\Role;
use App\Models\ScheduleEntry;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeachingLoad;
use App\Models\TeachingLoadItem;
use App\Services\AttendanceAnalysisService;
use Carbon\Carbon;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Тест до 10.08.2026 краснел через раз и всегда проходил в изоляции.
 *
 * Причина была не в наборе случайных чисел, как считалось: поведение
 * демонстрационного человека — успеваемость и привычка приходить вовремя —
 * выводилось из идентификатора строки. В полном прогоне последовательности
 * PostgreSQL не откатываются вместе с транзакцией, набор получал
 * идентификаторы с произвольного места (замер: 40..109 вместо 1..70), и
 * состав опаздывающих менялся от прогона к прогону вплоть до нуля.
 * Теперь поведение выводится из порядкового номера в наборе, и при
 * зафиксированной дате сводка одинакова в изоляции и в полном прогоне.
 *
 * Запас у проверки на опоздавших небольшой: приход всем назначается от 8:30,
 * а первую пару в это время ведут не все, поэтому опоздавших сегодня единицы.
 * Увидев здесь ноль после правки набора, ищите причину в ней, а не в удаче.
 */
class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Набор обязан переживать повторный запуск.
     *
     * Стенд наполняют не один раз, и второй запуск падал нарушением
     * уникальности: дисциплины плана раскладываются заново и получают новые
     * идентификаторы, а генератор нагрузки старых строк не узнавал и пытался
     * создать вторые такие же. В тестах база каждый раз пустая, поэтому увидеть
     * это можно было только здесь — на втором запуске подряд.
     */
    public function test_demo_data_seeder_survives_a_second_run(): void
    {
        Carbon::setTestNow('2026-07-27 12:00:00');

        foreach (['admin', 'teacher', 'student'] as $code) {
            Role::query()->firstOrCreate(['code' => $code], ['name' => $code]);
        }

        $this->seed(DemoDataSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(600, Student::query()->count(), 'Второй запуск не должен удваивать людей');
        $this->assertSame(70, Teacher::query()->count());
        $this->assertSame(12, Curriculum::query()->count());
        $this->assertGreaterThan(0, TeachingLoadItem::query()->count());

        Carbon::setTestNow();
    }

    public function test_demo_data_links_people_employees_and_realistic_attendance(): void
    {
        Carbon::setTestNow('2026-07-27 12:00:00');

        foreach (['admin', 'teacher', 'student'] as $code) {
            Role::query()->firstOrCreate(['code' => $code], ['name' => $code]);
        }

        // Сколько транзакций набор открывает за прогон. Под `RefreshDatabase`
        // внешняя транзакция есть всегда, поэтому каждая внутренняя — точка
        // сохранения, и её блокировка живёт до конца теста. Считаем здесь, а не
        // отдельным тестом: наполнение стоит полминуты, второй раз его гонять
        // незачем.
        $transactions = 0;
        Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
            $transactions++;
        });

        $this->seed(DemoDataSeeder::class);

        $this->assertSame(600, Student::query()->count());
        $this->assertSame(70, Teacher::query()->count());
        $this->assertGreaterThanOrEqual(670, Person::query()->count());
        $this->assertSame(600, Student::query()->whereNotNull('person_id')->count());
        $this->assertSame(70, Teacher::query()->whereNotNull('person_id')->count());
        $this->assertSame(70, Employee::query()->where('is_teacher', true)->count());
        $studentNames = Student::query()->get(['last_name', 'first_name', 'middle_name'])
            ->map(fn (Student $student): string => trim($student->last_name.' '.$student->first_name.' '.$student->middle_name))
            ->unique()
            ->count();
        $teacherNames = Teacher::query()->get(['last_name', 'first_name', 'middle_name'])
            ->map(fn (Teacher $teacher): string => trim($teacher->last_name.' '.$teacher->first_name.' '.$teacher->middle_name))
            ->unique()
            ->count();
        $this->assertGreaterThan(100, $studentNames);
        $this->assertGreaterThan(50, $teacherNames);

        $this->assertGreaterThan(0, AccessEvent::query()->where('result', AccessEvent::RESULT_DENIED)->count());
        $this->assertGreaterThan(0, AccessEvent::query()->where('direction', AccessEvent::DIRECTION_OUT)->count());

        // Журнал, учебные планы и нагрузка: экраны открывались пустыми при
        // полутора тысячах занятий в расписании, потому что их сущностей набор
        // не создавал вовсе.
        $this->assertGreaterThan(0, JournalLesson::query()->count(), 'Журнал обязан быть наполнен');
        $this->assertGreaterThan(0, JournalAttendance::query()->count());
        $this->assertGreaterThan(0, JournalGrade::query()->count());
        $this->assertGreaterThan(0, JournalLesson::query()->where('status', JournalLesson::STATUS_SIGNED)->count(), 'Без подписанных занятий не видно запрета на правку');
        $this->assertGreaterThan(0, JournalLesson::query()->where('status', JournalLesson::STATUS_IN_PROGRESS)->count(), 'Последний день журнала остаётся открытым');

        // Отметки в журнале и в отчётах — одни и те же: движок наполняется из
        // уже разложенной посещаемости, а не бросается заново.
        $this->assertSame(
            Attendance::query()->count(),
            JournalAttendance::query()->count(),
            'Посещаемость в журнале и в отчётах обязана совпадать'
        );

        $this->assertSame(12, Curriculum::query()->count(), 'По плану на каждую образовательную программу');
        $this->assertSame(0, Group::query()->whereNull('curriculum_id')->count(), 'Без плана у группы нагрузка не построится');
        $this->assertGreaterThan(0, TeachingLoad::query()->count());
        $this->assertGreaterThan(0, TeachingLoadItem::query()->whereNotNull('teacher_id')->count());
        $this->assertGreaterThan(0, TeachingLoadItem::query()->whereNull('teacher_id')->count(), 'Покрытие часов без нераспределённых строк ничего не показывает');

        // Движок расписания и выпускники — последние два раздела, открывавшиеся
        // пустыми. Покрытие часов считается по записям движка, привязанным к
        // строкам нагрузки: без привязки оно показывало бы ноль поставленных
        // часов у всех, то есть то же самое пустое место.
        $this->assertGreaterThan(0, ScheduleEntry::query()->count(), 'Движок расписания обязан быть наполнен');
        $this->assertGreaterThan(0, ScheduleEntry::query()->whereNotNull('teaching_load_item_id')->count(), 'Без связи с нагрузкой покрытие часов пустое');
        $this->assertGreaterThan(0, Graduate::query()->count());
        $this->assertGreaterThan(0, Diploma::query()->count());
        $this->assertGreaterThan(0, DiplomaSupplement::query()->count(), 'Без приложений не видно, что обратная загрузка их не теряет');
        $this->assertGreaterThan(0, Graduate::query()->where('status', 'ready')->count(), 'Реестр, где у всех всё выдано, не показывает работы');

        // Порог, а не точное число: набор растёт, и подгонять цифру под каждую
        // новую сущность бессмысленно. Важен порядок величины. До правки от
        // 12.08.2026 здесь было 4970 — набор в одиночку занимал 78 % таблицы
        // блокировок PostgreSQL (`max_locks_per_transaction` × `max_connections`
        // записей на весь сервер), и второго прогона рядом сервер не выдерживал.
        // Стало 60. Если счётчик снова уехал в тысячи — ищите `updateOrCreate`
        // или `firstOrCreate` в наборе: они создают строку через `createOrFirst`,
        // а тот внутри транзакции заворачивает вставку в `SAVEPOINT`. Писать
        // строки набор обязан через `DemoDataSeeder::writeRow`.
        $this->assertLessThan(
            200,
            $transactions,
            "Набор открыл {$transactions} транзакций: столько точек сохранения исчерпывают таблицу блокировок PostgreSQL"
        );

        $summary = app(AttendanceAnalysisService::class)->teachersToday()['summary'];
        $this->assertGreaterThan(0, $summary['late']);
        $this->assertGreaterThan(0, $summary['absent']);
        $this->assertGreaterThan(0, $summary['on_time']);

        Carbon::setTestNow();
    }
}
