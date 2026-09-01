<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Models\DormPayment;
use App\Models\DormPlacement;
use App\Models\DormRoom;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Person;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Студент видит свой блок и свою оплату — и ничего чужого.
 *
 * Владелец 01.09.2026: «студенту не нужно показывать соседей, он должен видеть
 * только своё».
 *
 * Главное здесь — **где** стоит это ограничение. Убрать фамилии соседей из
 * разметки легко, и экран будет выглядеть правильным; но данные, пришедшие в
 * ответе, видит всякий, кто откроет ответ, — это наш случай «спрятанная кнопка
 * не запрет». Поэтому сторож смотрит на **ответ сервера**, а не на страницу, и
 * ищет чужую фамилию во всём разделе целиком, а не в известных ему полях: поле,
 * которого он не знает, — ровно то, через которое сосед и просочится.
 */
class TheStudentSeesOnlyHisOwnDormTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_resident_sees_his_block_and_when_he_moved_in(): void
    {
        $student = $this->student('Проживающий');
        $this->settle($student, $this->block('201', 2, 3), '2026-09-01');

        $this->withApiAuth($this->accountFor($student));

        $this->getJson('/api/mobile/student')
            ->assertOk()
            ->assertJsonPath('data.dorm.room.number', '201')
            ->assertJsonPath('data.dorm.room.floor', 2)
            ->assertJsonPath('data.dorm.room.capacity', 3)
            ->assertJsonPath('data.dorm.moved_in_at', '2026-09-01');
    }

    public function test_no_neighbour_reaches_the_answer(): void
    {
        // Сосед живёт в том же блоке — то есть он есть в тех же связях, по
        // которым собирается ответ, и подмешать его туда можно одной строкой.
        $room = $this->block('201', 2, 3);
        $student = $this->student('Проживающий');
        $this->settle($student, $room, '2026-09-01');

        $neighbour = $this->student('Соседов');
        $this->settle($neighbour, $room, '2026-09-01');
        DormPayment::create(['student_id' => $neighbour->id, 'paid_through' => '2026-12-31', 'amount' => 9999]);

        $this->withApiAuth($this->accountFor($student));

        $dorm = $this->getJson('/api/mobile/student')->assertOk()->json('data.dorm');

        // Ищем во всём разделе целиком: поле, о котором сторож не знает, — ровно
        // то, через которое сосед и просочился бы.
        $whole = json_encode($dorm, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Соседов', $whole, 'чужая фамилия не должна доходить до студента');
        $this->assertStringNotContainsString('9999', $whole, 'чужая оплата не должна доходить до студента');

        // По идентификатору соседа тут не ищут нарочно: число вроде «2» совпало
        // бы с этажом или вместимостью, и сторож покраснел бы на невиновном —
        // а сторож, кричащий на невиновных, будет отключён. Чужие сущности
        // ловятся приметной фамилией и приметной суммой, чужие поля — проверкой
        // ниже, которая смотрит на состав раздела.
    }

    public function test_the_answer_does_not_say_how_many_live_there(): void
    {
        // Занятость не отдаётся не из вкуса: из «в блоке 3 места, занято 2»
        // следует, что рядом живёт кто-то ещё, а решение владельца — «только
        // своё». Вместимость отдаётся: она говорит, каков блок, но не кто в нём.
        $room = $this->block('201', 2, 3);
        $student = $this->student('Проживающий');
        $this->settle($student, $room, '2026-09-01');
        $this->settle($this->student('Соседов'), $room, '2026-09-01');

        $this->withApiAuth($this->accountFor($student));

        $dorm = $this->getJson('/api/mobile/student')->assertOk()->json('data.dorm');

        foreach (['occupied', 'free', 'people', 'residents', 'neighbours'] as $key) {
            $this->assertArrayNotHasKey($key, $dorm ?? [], "ответ не должен нести «{$key}»");
        }
    }

    public function test_his_own_payments_arrive_and_the_last_paid_date_with_them(): void
    {
        $student = $this->student('Проживающий');
        $this->settle($student, $this->block('201', 2, 3), '2026-09-01');

        DormPayment::create(['student_id' => $student->id, 'paid_through' => '2026-09-30', 'amount' => 1500, 'paid_at' => '2026-09-01']);
        DormPayment::create(['student_id' => $student->id, 'paid_through' => '2026-10-31', 'amount' => 1500, 'paid_at' => '2026-10-01']);

        $this->withApiAuth($this->accountFor($student));

        $this->getJson('/api/mobile/student')
            ->assertOk()
            ->assertJsonCount(2, 'data.dorm.payments')
            ->assertJsonPath('data.dorm.paid_through', '2026-10-31');
    }

    public function test_a_superseded_payment_is_not_shown(): void
    {
        // Замещённая отметка — это исправленная запись, а не второй платёж.
        // Показать обе значило бы показать историю правок там, где человек ждёт
        // факт, и «оплачено по» уехало бы на замещённую дату.
        $student = $this->student('Проживающий');
        $this->settle($student, $this->block('201', 2, 3), '2026-09-01');

        $correct = DormPayment::create(['student_id' => $student->id, 'paid_through' => '2026-09-30', 'amount' => 1500]);
        DormPayment::create(['student_id' => $student->id, 'paid_through' => '2026-12-31', 'amount' => 1, 'superseded_by_id' => $correct->id]);

        $this->withApiAuth($this->accountFor($student));

        $this->getJson('/api/mobile/student')
            ->assertOk()
            ->assertJsonCount(1, 'data.dorm.payments')
            ->assertJsonPath('data.dorm.paid_through', '2026-09-30');
    }

    public function test_a_student_who_does_not_live_there_gets_nothing(): void
    {
        // У 574 студентов из 596 заселения нет, и раздел им не показывается
        // вовсе. Пустой раздел «Общежитие» у них был бы шумом, а не заботой.
        $student = $this->student('Домашний');

        $this->withApiAuth($this->accountFor($student));

        $this->getJson('/api/mobile/student')->assertOk()->assertJsonPath('data.dorm', null);
    }

    public function test_a_moved_out_student_gets_nothing_either(): void
    {
        $student = $this->student('Выехавший');
        $placement = $this->settle($student, $this->block('201', 2, 3), '2026-09-01');
        $placement->update(['moved_out_at' => '2026-09-15']);

        $this->withApiAuth($this->accountFor($student));

        $this->getJson('/api/mobile/student')->assertOk()->assertJsonPath('data.dorm', null);
    }

    private function block(string $number, int $floor, int $capacity): DormRoom
    {
        $building = Building::query()->firstWhere('code', 'SER277') ?? Building::create([
            'name' => 'Общежитие на Серова, 277',
            'code' => 'SER277',
            'is_active' => true,
        ]);

        return DormRoom::query()->firstWhere('number', $number) ?? DormRoom::create([
            'building_id' => $building->id,
            'number' => $number,
            'floor' => $floor,
            'capacity' => $capacity,
            'kind' => DormRoom::KIND_REGULAR,
            'is_active' => true,
        ]);
    }

    private function settle(Student $student, DormRoom $room, string $movedIn): DormPlacement
    {
        return DormPlacement::create([
            'dorm_room_id' => $room->id,
            'student_id' => $student->id,
            'moved_in_at' => $movedIn,
        ]);
    }

    private function student(string $lastName): Student
    {
        $group = Group::query()->firstWhere('name', 'ИСП-101') ?? Group::create([
            'name' => 'ИСП-101',
            'specialty' => 'Инструментальное исполнительство',
            'course' => 1,
            'year_start' => 2026,
        ]);

        $person = Person::create(['last_name' => $lastName, 'first_name' => 'Иван', 'status' => 'active']);

        return Student::create([
            'group_id' => $group->id,
            'person_id' => $person->id,
            'last_name' => $lastName,
            'first_name' => 'Иван',
            'status' => 'active',
        ]);
    }

    private function accountFor(Student $student): User
    {
        $user = User::factory()->create(['is_active' => true, 'person_id' => $student->person_id]);

        // Кабинет находит студента через `students.user_id`, а не через карточку
        // человека: `User::student()` — это `hasOne(Student::class)`. Без этой
        // строки ручка отвечает «пользователь не связан с карточкой студента», и
        // проверки про пустой раздел остаются зелёными по неверной причине.
        $student->forceFill(['user_id' => $user->id])->save();

        $role = Role::query()->firstOrCreate(['code' => 'student_cabinet'], ['name' => 'Студент (проба)', 'description' => 'Свой кабинет']);

        $permission = Permission::query()->firstOrCreate(
            ['code' => 'mobile.student.view'],
            ['name' => 'mobile.student.view', 'module' => 'Test', 'description' => 'кабинет', 'system' => true, 'active' => true],
        );

        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($role->id);

        return $user;
    }
}
