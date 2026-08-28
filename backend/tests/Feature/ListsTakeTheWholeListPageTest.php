<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Http\PageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Список, который дочитывают целиком, обязан принимать целую страницу.
 *
 * 28.08.2026 владелец открыл «RFID-карты» → «Реестр карт» и увидел «Карт нет»
 * при 244 картах в базе. Соседняя вкладка «Журнал» показывала те же карты, и
 * это сбивало с толку сильнее самой пустоты.
 *
 * Причина: фронтенд дочитывает списки помощником `api.listAll`, а тот всегда
 * просит `per_page=500` — ровно `PageSize::MAX`. Ручка реестра проверяла
 * `max:200` и отвечала **422**. Экран получал ошибку вместо строк и рисовал
 * пустое состояние; счётчики, считаемые по тем же строкам, показывали нули.
 * Журнал работал потому, что его собственный потолок был 500.
 *
 * Замер 28.08.2026 по стенду: на `per_page=500` отвечали 422 пять ручек —
 * `rfid-cards`, `dorm/rooms`, `dorm/incidents`, `dorm/conduct`, `dorm/social`;
 * `dorm/placements`, `dorm/leaves` и `rfid-cards/journal` отвечали 200.
 *
 * **Сторож проверяет не «нет ли где `max:200`», а то, что ручка отвечает на
 * страницу, которую ей пошлют.** Первое — счёт по признаку, который завтра
 * запишут иначе; второе не обойти.
 */
class ListsTakeTheWholeListPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Списки области, которые фронтенд дочитывает целиком через `api.listAll`.
     *
     * Перечень руками — и это его слабое место: он разойдётся со `stores/`,
     * если новый список заведут и сюда не впишут. Читать вызовы `listAll` из
     * фронтенда прямо в тесте нельзя: бэкенд монтируется без него, и проверка
     * молча пропускалась бы — так уже было с `MenuMatchesPermissionsTest`.
     * Поэтому здесь списки своей области; за соседние отвечают соседи.
     */
    private const OWN_LISTS = [
        'rfid-cards',
        'rfid-cards/journal',
        'dorm/rooms',
        'dorm/placements',
        'dorm/leaves',
        'dorm/incidents',
        'dorm/conduct',
        'dorm/social',
    ];

    public function test_every_list_of_the_area_answers_the_whole_list_page(): void
    {
        $this->withApiAuth($this->userWithEverything());

        $refused = [];

        foreach (self::OWN_LISTS as $list) {
            $status = $this->getJson('/api/'.$list.'?per_page='.PageSize::MAX)->getStatusCode();

            if ($status !== 200) {
                $refused[] = $list.' → '.$status;
            }
        }

        $this->assertSame([], $refused,
            'Эти списки не принимают страницу, которую им шлёт `api.listAll`, и экран получит ошибку вместо строк: '
            .implode(', ', $refused));
    }

    public function test_a_page_bigger_than_the_ceiling_is_trimmed_not_refused(): void
    {
        // Потолок урезает, а не отказывает: иначе достаточно попросить больше —
        // и вместо строк придёт 422. Просим заведомо лишнее.
        $this->withApiAuth($this->userWithEverything());

        $this->getJson('/api/rfid-cards?per_page='.(PageSize::MAX * 10))
            ->assertOk()
            ->assertJsonPath('meta.per_page', PageSize::MAX);
    }

    /** Пользователь со всеми правами области: проверяем размер страницы, а не доступ. */
    private function userWithEverything(): User
    {
        // Права взяты у самих маршрутов, а не по памяти: у общежития их семь
        // разных, и «dorm.view» среди них нет вовсе — первая редакция теста
        // получила четыре 403 и выглядела находкой, хотя ошибалась в правах.
        $codes = [
            'rfid.cards.view',
            'dorm.rooms.view', 'dorm.placements.view', 'dorm.absences.view',
            'dorm.incidents.view', 'dorm.conduct.view', 'dorm.social.view',
            'dorm.payments.view',
            'people.view',
        ];

        $user = User::factory()->create(['is_active' => true]);
        $role = Role::firstOrCreate(
            ['code' => 'lists_page_size'],
            ['name' => 'Размер страницы', 'description' => 'Test role'],
        );

        foreach ($codes as $code) {
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
