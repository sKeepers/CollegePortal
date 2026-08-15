<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\DigitalIdentity;
use App\Models\Group;
use App\Models\Student;
use App\Services\AttendanceAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Разбор посещаемости одной группы спрашивает проходы только про её людей.
 *
 * Находка 4 аудита от 11.08.2026. Отбор по группе применялся к студентам и
 * занятиям, а до событий не доходил: запрос брал всё за период по типу сущности
 * и результату. Замер на стенде 15.08.2026 — на группу из двадцати человек
 * поднималось 5736 событий, из которых к ней относились 210, то есть 3,7 %.
 *
 * Больно не было и не стало: на двух неделях демонстрационных данных разница в
 * миллисекундах. Дело в том, что цена росла вместе с общим потоком проходной, а
 * не с тем, что спросили, — за учебный год это сотни тысяч строк ради двадцати
 * человек.
 *
 * Тест смотрит на то, о ком спрашивают, а не на время: время в CI и на стенде
 * разное, а «не спрашивать про чужих» — то самое свойство, которое сломалось.
 */
class AttendanceEventsScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_analysis_asks_only_about_its_own_students(): void
    {
        $mine = $this->groupWithStudents('ИСП-101', 2);
        $foreign = $this->groupWithStudents('ВОК-201', 3);

        $bindings = $this->accessEventBindings($mine['group']->id);

        $this->assertCount(1, $bindings, 'Проходы должны браться одним запросом');

        foreach ($mine['students'] as $student) {
            $this->assertContains($student->id, $bindings[0], 'Про своих студентов спросить обязаны');
        }

        foreach ($foreign['students'] as $student) {
            $this->assertNotContains($student->id, $bindings[0], 'Про чужую группу спрашивать незачем');
        }
    }

    /**
     * Отдельно — случай, в котором лёгкая реализация ломается: людей нет вовсе.
     * Пустой список не должен означать «спросить про всех».
     */
    public function test_a_group_without_students_reads_no_events_at_all(): void
    {
        $this->groupWithStudents('ВОК-201', 3);
        $empty = Group::query()->create([
            'name' => 'ПУСТ-301',
            'specialty' => 'Теория музыки',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $this->assertSame([], $this->accessEventBindings($empty->id));
    }

    /**
     * Разбор остаётся верным: студент, входивший сегодня, виден как пришедший, а
     * не как отсутствующий.
     */
    public function test_the_analysis_still_sees_the_entry(): void
    {
        $mine = $this->groupWithStudents('ИСП-101', 2);

        $rows = app(AttendanceAnalysisService::class)->students([
            'group_id' => $mine['group']->id,
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ])['data'];

        $this->assertCount(2, $rows);
        $this->assertNotEmpty($rows[0]['first_entry'], 'Вход студента обязан доехать до строки разбора');
    }

    /**
     * @return array<int, array<int, mixed>> привязки запросов к `access_events`
     */
    private function accessEventBindings(int $groupId): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        app(AttendanceAnalysisService::class)->students([
            'group_id' => $groupId,
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]);

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return collect($log)
            ->filter(fn (array $query): bool => str_contains($query['query'], 'from "access_events"'))
            ->pluck('bindings')
            ->values()
            ->all();
    }

    /**
     * @return array{group: Group, students: \Illuminate\Support\Collection<int, Student>}
     */
    private function groupWithStudents(string $name, int $count): array
    {
        $group = Group::query()->create([
            'name' => $name,
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $students = collect(range(1, $count))->map(function (int $index) use ($group, $name): Student {
            $student = Student::create([
                'group_id' => $group->id,
                'last_name' => $name.'-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'first_name' => 'Имя',
                'status' => 'active',
            ]);

            $identity = DigitalIdentity::create([
                'entity_type' => 'student',
                'entity_id' => $student->id,
                'token' => (string) Str::uuid(),
                'status' => 'active',
                'issued_at' => now(),
            ]);

            AccessEvent::create([
                'digital_identity_id' => $identity->id,
                'entity_type' => 'student',
                'entity_id' => $student->id,
                'direction' => AccessEvent::DIRECTION_IN,
                'event_time' => now()->setTime(8, 30),
                'result' => AccessEvent::RESULT_ALLOWED,
            ]);

            return $student;
        });

        return ['group' => $group, 'students' => $students];
    }
}
