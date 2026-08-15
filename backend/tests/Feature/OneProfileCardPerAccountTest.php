<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Одна учебная карточка на учётную запись.
 *
 * `User::teacher()` и `User::student()` — `hasOne`, и оба Request на карточку
 * объявляют `unique:...,user_id`. Правило было, гарантии не было: на стенде у
 * `teacher@local` оказались две карточки, `hasOne` брал первую — пустую, — и
 * кабинет с журналом выглядели пустыми при 21 занятии на второй.
 *
 * Сделали их два инструмента подряд, оба в обход формы: `portal:merge-accounts`
 * переносил на выжившую запись все карточки проигравших, а демонстрационный
 * набор потом привязывал к той же записи свою.
 */
class OneProfileCardPerAccountTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Слияние служебных записей — то самое место, где дубль и появился. Карточка
     * проигравшего переезжает, только если у выжившего своей нет; иначе остаётся
     * без учётной записи, но остаётся: удалять чужой профиль команда не вправе.
     */
    public function test_merging_accounts_leaves_one_card_and_releases_the_other(): void
    {
        $role = Role::query()->firstOrCreate(['code' => 'teacher'], ['name' => 'Преподаватель']);

        $legacy = $this->account('teacher1.uat@college-portal.local', $role);
        $canonical = $this->account('teacher@local', $role);

        $uatCard = $this->card($legacy, 'teacher1.uat@college-portal.local', 'Преподаватель');
        $demoCard = $this->card($canonical, 'teacher@local', 'Кузьмина');

        $this->artisan('portal:merge-accounts', ['--apply' => true])->assertSuccessful();

        $survivor = User::query()->where('email', 'teacher@local')->firstOrFail();

        $this->assertSame(
            1,
            Teacher::query()->where('user_id', $survivor->id)->count(),
            'За учётной записью остаётся ровно одна карточка'
        );

        // Ни одна из карточек не пропала: вторая просто осталась без записи и
        // видна в реестре преподавателей.
        $this->assertNotNull($uatCard->fresh(), 'Чужую карточку удалять нельзя');
        $this->assertNotNull($demoCard->fresh());

        $orphan = collect([$uatCard->fresh(), $demoCard->fresh()])->firstWhere('user_id', null);
        $this->assertNotNull($orphan, 'Одна из двух обязана остаться без учётной записи');
    }

    /**
     * Ни один инструмент больше не заведёт вторую карточку молча: запрет стоит в
     * базе. Проверка идёт последним действием теста — упавшая вставка отравляет
     * транзакцию PostgreSQL целиком, и любой запрос после неё падал бы не там,
     * где ошибка.
     */
    public function test_a_second_card_cannot_be_attached_to_the_same_account(): void
    {
        $role = Role::query()->firstOrCreate(['code' => 'teacher'], ['name' => 'Преподаватель']);
        $user = $this->account('teacher@local', $role);
        $this->card($user, 'teacher@local', 'Кузьмина');

        $this->expectException(QueryException::class);

        $this->card($user, 'second@local', 'Вторая');
    }

    /**
     * Мягко удалённая карточка запрета не держит: иначе учётная запись, у которой
     * профиль удалили, не смогла бы получить новый.
     */
    public function test_a_deleted_card_does_not_block_a_new_one(): void
    {
        $role = Role::query()->firstOrCreate(['code' => 'teacher'], ['name' => 'Преподаватель']);
        $user = $this->account('teacher@local', $role);

        $this->card($user, 'teacher@local', 'Кузьмина')->delete();
        $fresh = $this->card($user, 'new@local', 'Новая');

        $this->assertSame(1, Teacher::query()->where('user_id', $user->id)->count());
        $this->assertSame($user->id, $fresh->user_id);
    }

    private function account(string $email, Role $role): User
    {
        return User::factory()->create([
            'email' => $email,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    private function card(User $user, string $email, string $lastName): Teacher
    {
        return Teacher::create([
            'user_id' => $user->id,
            'email' => $email,
            'last_name' => $lastName,
            'first_name' => 'Имя',
            'is_active' => true,
        ]);
    }
}
