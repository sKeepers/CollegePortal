<?php

namespace Tests\Feature;

use App\Models\DeletionRequest;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Удаление в два шага и корзина.
 *
 * Правило владельца от 10.08.2026: удаляет только администратор, остальные
 * помечают ошибочно заведённую карточку и объясняют причину.
 */
class DeletionRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_card_is_marked_with_a_reason_and_waits_for_the_administrator(): void
    {
        $this->seed(RoleSeeder::class);
        $student = $this->createStudent('Ошибочный');
        $this->withApiAuth($this->userWith(['students.view', 'trash.request']));

        $this->postJson('/api/deletion-requests', [
            'subject_type' => 'student',
            'subject_id' => $student->id,
            'reason' => 'Карточка заведена дважды, эта лишняя.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subject_label', 'Ошибочный Проверочный');

        // Карточка на месте: пометка ничего не удаляет.
        $this->assertNotNull(Student::query()->find($student->id));
    }

    public function test_a_reason_is_required_and_a_second_request_is_refused(): void
    {
        $this->seed(RoleSeeder::class);
        $student = $this->createStudent('Ошибочный');
        $this->withApiAuth($this->userWith(['students.view', 'trash.request']));

        $this->postJson('/api/deletion-requests', ['subject_type' => 'student', 'subject_id' => $student->id])
            ->assertStatus(422);

        $payload = ['subject_type' => 'student', 'subject_id' => $student->id, 'reason' => 'Дубль карточки.'];
        $this->postJson('/api/deletion-requests', $payload)->assertCreated();
        $this->postJson('/api/deletion-requests', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.subject_id.0', 'На эту карточку уже есть заявка на удаление, ожидающая решения.');
    }

    public function test_the_administrator_approves_and_the_card_goes_to_the_trash(): void
    {
        $this->seed(RoleSeeder::class);
        $student = $this->createStudent('Ошибочный');
        $request = $this->requestDeletion($student);

        $this->withApiAuth($this->userWith(['trash.manage']));
        $this->postJson("/api/deletion-requests/{$request->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Из списка пропала, из базы — нет: корзина, а не стирание.
        $this->assertNull(Student::query()->find($student->id));
        $this->assertNotNull(Student::withTrashed()->find($student->id));

        $this->getJson('/api/trash')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'student')
            ->assertJsonPath('data.0.label', 'Ошибочный Проверочный')
            ->assertJsonPath('data.0.reason', 'Карточка заведена дважды, эта лишняя.');
    }

    public function test_a_rejected_request_leaves_the_card_alone(): void
    {
        $this->seed(RoleSeeder::class);
        $student = $this->createStudent('Нужный');
        $request = $this->requestDeletion($student);

        $this->withApiAuth($this->userWith(['trash.manage']));
        $this->postJson("/api/deletion-requests/{$request->id}/reject", ['comment' => 'Карточка настоящая.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.review_comment', 'Карточка настоящая.');

        $this->assertNotNull(Student::query()->find($student->id));

        // Повторное решение по закрытой заявке не проходит.
        $this->postJson("/api/deletion-requests/{$request->id}/approve")->assertStatus(422);
    }

    public function test_the_trash_gives_the_card_back_and_can_be_emptied(): void
    {
        $this->seed(RoleSeeder::class);
        $student = $this->createStudent('Ошибочный');
        $request = $this->requestDeletion($student);
        $this->withApiAuth($this->userWith(['trash.manage']));
        $this->postJson("/api/deletion-requests/{$request->id}/approve")->assertOk();

        $this->postJson("/api/trash/student/{$student->id}/restore")->assertOk();
        $this->assertNotNull(Student::query()->find($student->id));

        // `requestDeletion` входит под тем, кто помечает, — для решения снова
        // нужен администратор.
        $second = $this->requestDeletion($student, 'Всё-таки дубль.');
        $this->withApiAuth($this->userWith(['trash.manage']));
        $this->postJson("/api/deletion-requests/{$second->id}/approve")->assertOk();
        $this->deleteJson("/api/trash/student/{$student->id}")->assertOk();

        // Из корзины возврата уже нет.
        $this->assertNull(Student::withTrashed()->find($student->id));
    }

    public function test_only_the_administrator_decides_and_sees_the_trash(): void
    {
        $this->seed(RoleSeeder::class);
        $student = $this->createStudent('Ошибочный');
        $request = $this->requestDeletion($student);

        $this->withApiAuth($this->userWith(['students.view', 'trash.request']));
        $this->postJson("/api/deletion-requests/{$request->id}/approve")->assertForbidden();
        $this->getJson('/api/trash')->assertForbidden();
        $this->getJson('/api/deletion-requests/pending')->assertForbidden();
    }

    public function test_the_pending_queue_feeds_the_administrator_bell(): void
    {
        $this->seed(RoleSeeder::class);
        $this->requestDeletion($this->createStudent('Первый'));
        $this->requestDeletion($this->createStudent('Второй'), 'Второй дубль.');

        $this->withApiAuth($this->userWith(['trash.manage']));
        $this->getJson('/api/deletion-requests/pending')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.subject_label', 'Первый Проверочный');
    }

    /**
     * Правило владельца соблюдается целиком только тогда, когда прямое удаление
     * закрыто. До 11.08.2026 маршруты `DELETE` требовали `students.delete`,
     * `teachers.delete` и `groups.delete`, эти права были у «Учебной части 2» и
     * заместителя, и удаление шло мимо заявки: карточка уходила в корзину, но
     * без проверки администратором.
     */
    public function test_direct_deletion_is_closed_to_everyone_but_the_administrator(): void
    {
        $this->seed(RoleSeeder::class);
        $student = $this->createStudent('Ошибочный');
        $group = Group::query()->firstOrFail();

        // Полный набор прав на ведение карточек — и всё равно не удаляет.
        $this->withApiAuth($this->userWith([
            'students.view', 'students.create', 'students.update',
            'teachers.view', 'teachers.create', 'teachers.update',
            'groups.view', 'groups.create', 'groups.update',
            'trash.request',
        ]));

        $this->deleteJson("/api/students/{$student->id}")->assertForbidden();
        $this->deleteJson("/api/groups/{$group->id}")->assertForbidden();
        $this->assertNotNull(Student::query()->find($student->id));

        $this->withApiAuth($this->userWith(['trash.manage']));
        $this->deleteJson("/api/students/{$student->id}")->assertSuccessful();
    }

    /**
     * Права удаления выведены из употребления, и вернуть их роли уже нечем:
     * неактивное право не проходит `User::hasPermission`. Проверяем, что
     * маршрут не открывается тем, кто держит старое право.
     */
    public function test_the_retired_delete_permissions_no_longer_open_the_route(): void
    {
        $this->seed(RoleSeeder::class);
        $student = $this->createStudent('Ошибочный');

        $this->withApiAuth($this->userWith(['students.view', 'students.delete']));

        $this->deleteJson("/api/students/{$student->id}")->assertForbidden();
        $this->assertNotNull(Student::query()->find($student->id));
    }

    private function requestDeletion(Student $student, string $reason = 'Карточка заведена дважды, эта лишняя.'): DeletionRequest
    {
        $this->withApiAuth($this->userWith(['students.view', 'trash.request']));

        $response = $this->postJson('/api/deletion-requests', [
            'subject_type' => 'student',
            'subject_id' => $student->id,
            'reason' => $reason,
        ])->assertCreated();

        return DeletionRequest::query()->findOrFail($response->json('data.id'));
    }

    private function createStudent(string $lastName): Student
    {
        $group = Group::query()->create(['name' => 'Г-'.$lastName, 'specialty' => 'Проверка', 'course' => 1, 'year_start' => 2026]);

        return Student::query()->create([
            'group_id' => $group->id,
            'last_name' => $lastName,
            'first_name' => 'Проверочный',
            'status' => 'active',
        ]);
    }

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        // Роль переиспользуется: помощник вызывается по нескольку раз за тест,
        // а код роли выводится из набора прав.
        $role = Role::firstOrCreate(
            ['code' => 'trash_'.substr(md5(json_encode($permissions)), 0, 12)],
            ['name' => 'Корзина '.substr(md5(json_encode($permissions)), 0, 8), 'description' => 'Test role'],
        );

        foreach ($permissions as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => 'Test', 'description' => $code, 'system' => true, 'active' => true],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user->roles()->attach($role->id);

        return $user;
    }
}
