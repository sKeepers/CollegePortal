<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Журнал аудита листается на сервере и не теряет строк.
 *
 * До 29.08.2026 экран забирал 200 строк и листал их у себя: подпись читалась
 * «1 - 20 из 200» при 16 977 записях в журнале. Оператор идёт туда искать, что
 * произошло, и двести читаются как «больше ничего не было».
 *
 * Вторая половина — порядок. Сортировки по `created_at` для постраничности
 * мало: на стенде у 395 меток времени есть дубли, а на одну секунду приходится
 * 253 записи. Без `id` вторым ключом база вправе вернуть совпавшие по времени
 * в любом порядке, и при переходе на вторую страницу часть строк показалась бы
 * дважды, а часть не показалась бы вовсе. Проверяется это здесь обходом всех
 * страниц: каждая запись обязана встретиться ровно один раз.
 */
class AuditLogPagingTest extends TestCase
{
    use RefreshDatabase;

    private ?User $reader = null;

    /**
     * Один и тот же смотритель на весь тест: обход страниц зовёт этот помощник
     * четыре раза, и создание нового каждый раз падало на уникальном email.
     */
    private function admin(): User
    {
        if ($this->reader !== null) {
            return $this->reader;
        }

        $role = Role::query()->where('code', 'admin')->firstOrFail();

        return $this->reader = User::query()->create([
            'name' => 'Смотритель журнала',
            'email' => 'audit-reader@example.test',
            'password' => Hash::make('secret-secret'),
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    private function seedLogs(int $count, string $moment = '2026-08-20 10:00:00'): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'user_id' => null,
                'module' => $i % 2 === 0 ? 'students' : 'gate',
                'action' => $i % 3 === 0 ? 'created' : 'viewed',
                'entity_type' => 'Student',
                'entity_id' => $i + 1,
                'ip_address' => '10.0.0.1',
                // Одно и то же время у всех: именно этот случай ломал постраничность.
                'created_at' => $moment,
            ];
        }

        AuditLog::query()->insert($rows);
    }

    public function test_every_entry_appears_exactly_once_across_pages(): void
    {
        $this->seedLogs(65);

        $seen = [];

        for ($page = 1; $page <= 4; $page++) {
            $response = $this->withApiAuth($this->admin())
                ->getJson("/api/admin/audit?per_page=20&page={$page}");

            $response->assertOk();

            foreach ($response->json('data') as $row) {
                $seen[] = $row['id'];
            }
        }

        $this->assertCount(65, $seen, 'страницы отдали не все записи или отдали лишние');
        $this->assertCount(65, array_unique($seen), 'какая-то запись попала на две страницы');

        // Полнота обхода — ещё не порядок. На неподвижной таблице обе базы
        // отдают совпавшие по времени строки одинаково от запроса к запросу:
        // замерено 29.08.2026, тест проходил и **без** второго ключа, и на
        // SQLite, и на PostgreSQL. А журнал аудита неподвижным не бывает —
        // между двумя запросами страниц в него пишут, и тогда порядок без
        // однозначного ключа расходится: строка уезжает на другую страницу.
        //
        // Поэтому проверяется сам ключ, а не его следствие: при одинаковом
        // времени номера обязаны идти строго по убыванию. Без `orderBy('id')`
        // они идут по возрастанию — в порядке вставки, — и это видно сразу.
        $descending = $seen;
        rsort($descending);

        $this->assertSame($descending, $seen, 'порядок внутри одного времени не однозначен: нужен второй ключ сортировки');
    }

    public function test_the_total_is_the_whole_journal_not_the_page(): void
    {
        $this->seedLogs(65);

        $response = $this->withApiAuth($this->admin())->getJson('/api/admin/audit?per_page=20&page=1');

        $response->assertOk();
        $this->assertCount(20, $response->json('data'), 'страница должна быть в двадцать строк');
        $this->assertSame(65, $response->json('meta.total'), 'экран назовёт это число оператору');
        $this->assertSame(4, $response->json('meta.last_page'));
    }

    public function test_filter_options_cover_the_whole_journal(): void
    {
        $this->seedLogs(65);

        // Значение, которого на первой странице заведомо нет.
        AuditLog::query()->insert([[
            'user_id' => null,
            'module' => 'dormitory',
            'action' => 'settled',
            'entity_type' => 'Student',
            'entity_id' => 999,
            'ip_address' => '10.0.0.2',
            'created_at' => '2020-01-01 00:00:00',
        ]]);

        $response = $this->withApiAuth($this->admin())->getJson('/api/admin/audit?per_page=20&page=1');

        $response->assertOk();
        $this->assertContains('dormitory', $response->json('options.modules'), 'поле отбора собрано по странице, а не по журналу');
        $this->assertContains('settled', $response->json('options.actions'));
    }

    public function test_a_request_for_more_than_the_ceiling_is_capped_not_refused(): void
    {
        $this->seedLogs(30);

        $response = $this->withApiAuth($this->admin())->getJson('/api/admin/audit?per_page=100000');

        $response->assertOk();
        $this->assertLessThanOrEqual(500, (int) $response->json('meta.per_page'), 'потолок размера страницы не действует');
    }

    public function test_direction_turns_the_journal_over(): void
    {
        $this->seedLogs(3, '2026-08-20 10:00:00');

        $newest = $this->withApiAuth($this->admin())->getJson('/api/admin/audit?per_page=3');
        $oldest = $this->withApiAuth($this->admin())->getJson('/api/admin/audit?per_page=3&direction=asc');

        $newest->assertOk();
        $oldest->assertOk();

        $this->assertSame(
            array_reverse($newest->json('data.*.id')),
            $oldest->json('data.*.id'),
            'обратный порядок должен переворачивать выдачу, а не повторять её',
        );
    }
}
