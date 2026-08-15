<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Building;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AccessPointResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Справочник корпусов: настоящие три записи и правила правки.
 *
 * До 15.08.2026 `buildings` и `access_points` были пусты у всех — и на стенде, и
 * на PROD, — поэтому отчёт «Кто в здании» складывал всех в группу «точка вне
 * справочника». Записи заводит миграция, а не сидер: сидер выполняется при
 * установке и больше никогда, а система уже стоит.
 */
class AccessBuildingsDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_creates_the_three_real_buildings_with_one_point_each(): void
    {
        $expected = [
            'GOL21' => ['Учебный корпус на Голенева, 21', 'ул. Голенева, 21'],
            'KRU31' => ['Учебный корпус на Крупской, 31', 'ул. Крупской, 31'],
            'SER277' => ['Общежитие на Серова, 277', 'ул. Серова, 277'],
        ];

        foreach ($expected as $code => [$name, $address]) {
            $building = Building::query()->where('code', $code)->first();

            $this->assertNotNull($building, "Корпус {$code} не заведён миграцией");
            $this->assertSame($name, $building->name);
            $this->assertSame($address, $building->address);
            $this->assertTrue($building->is_active);
            $this->assertSame(1, $building->accessPoints()->count(), 'У корпуса ровно одна точка прохода');
            $this->assertSame($code, $building->accessPoints()->value('code'));
        }
    }

    /**
     * Ради этого код и нужен: сканер присылает строку, которую в него прописали
     * при установке. Короткий латинский код набрать без опечатки проще, чем
     * «Проходная на Голенева, 21», и регистр с пробелами значения не имеют.
     */
    public function test_the_scanner_finds_the_point_by_its_code(): void
    {
        $resolved = app(AccessPointResolver::class)->resolve('  gol21 ');

        $this->assertNotNull($resolved);
        $this->assertSame('Проходная на Голенева, 21', $resolved->name);
    }

    /**
     * Названия точек различаются между корпусами намеренно: уникальность имени
     * действует в пределах корпуса, и три «Проходной» завелись бы молча, а
     * сопоставление по имени отдавало бы первую попавшуюся из трёх.
     */
    public function test_point_names_are_unique_across_the_whole_directory(): void
    {
        $names = AccessPoint::query()->pluck('name');

        $this->assertSame($names->count(), $names->unique()->count());
    }

    /**
     * Правка с экрана отвечала `405`: интерфейс слал `PATCH`, маршрут объявлен
     * на `PUT`. Стор переведён на `PUT`; тест держит рабочим тот способ, каким
     * экран действительно сохраняет.
     */
    public function test_building_and_point_are_saved_over_put(): void
    {
        $this->withApiAuth();
        $building = Building::query()->where('code', 'GOL21')->firstOrFail();
        $point = $building->accessPoints()->firstOrFail();

        $this->putJson("/api/access/buildings/{$building->id}", [
            'name' => 'Учебный корпус на Голенева, 21',
            'code' => 'GOL21',
            'address' => 'ул. Голенева, 21, корпус А',
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.address', 'ул. Голенева, 21, корпус А');

        $this->putJson("/api/access/points/{$point->id}", [
            'building_id' => $building->id,
            'name' => 'Проходная на Голенева, 21',
            'code' => 'GOL21',
            'description' => 'Турникет у входа.',
            'is_active' => true,
        ])->assertOk()->assertJsonPath('data.description', 'Турникет у входа.');
    }

    public function test_only_the_administrator_changes_the_point_code(): void
    {
        $point = AccessPoint::query()->where('code', 'GOL21')->firstOrFail();
        $keeper = $this->userWithPointsPermission();

        $payload = fn (array $override = []): array => array_merge([
            'building_id' => $point->building_id,
            'name' => 'Проходная на Голенева, 21',
            'code' => 'GOL21',
            'is_active' => true,
        ], $override);

        // Название правит любой, у кого есть право на справочник.
        $this->withApiAuth($keeper)
            ->putJson("/api/access/points/{$point->id}", $payload(['name' => 'Проходная (главный вход)']))
            ->assertOk();

        // Код — нет. Ни на другой…
        $this->withApiAuth($keeper)
            ->putJson("/api/access/points/{$point->id}", $payload(['name' => 'Проходная (главный вход)', 'code' => 'GOL22']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        // …ни в пустой: очистка кода — такая же правка, и рядом с `nullable`
        // проверка правилом бы не сработала вовсе.
        $this->withApiAuth($keeper)
            ->putJson("/api/access/points/{$point->id}", $payload(['name' => 'Проходная (главный вход)', 'code' => null]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->assertSame('GOL21', $point->fresh()->code);

        // Тот же код в другом регистре — не правка, а форма, вернувшая запись
        // целиком: она проходит и хранение не меняет.
        $this->withApiAuth($keeper)
            ->putJson("/api/access/points/{$point->id}", $payload(['name' => 'Проходная (главный вход)', 'code' => ' gol21 ']))
            ->assertOk();

        $this->assertSame('GOL21', $point->fresh()->code);

        $this->withApiAuth()
            ->putJson("/api/access/points/{$point->id}", $payload(['name' => 'Проходная (главный вход)', 'code' => 'GOL21A']))
            ->assertOk();

        $this->assertSame('GOL21A', $point->fresh()->code);
    }

    public function test_a_new_point_from_a_non_administrator_carries_no_code(): void
    {
        $building = Building::query()->where('code', 'KRU31')->firstOrFail();
        $keeper = $this->userWithPointsPermission();

        $this->withApiAuth($keeper)
            ->postJson('/api/access/points', [
                'building_id' => $building->id,
                'name' => 'Служебный вход на Крупской',
                'code' => 'KRU31S',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');

        $this->withApiAuth($keeper)
            ->postJson('/api/access/points', [
                'building_id' => $building->id,
                'name' => 'Служебный вход на Крупской',
            ])
            ->assertSuccessful();

        $this->assertNull(AccessPoint::query()->where('name', 'Служебный вход на Крупской')->value('code'));
    }

    private function userWithPointsPermission(): \App\Models\User
    {
        $permission = Permission::query()->firstOrCreate(
            ['code' => 'gate.points.manage'],
            ['name' => 'Проходная: справочник точек']
        );

        Role::query()
            ->firstOrCreate(['code' => 'security'], ['name' => 'Сотрудник проходной'])
            ->permissions()->sync([$permission->id]);

        return $this->createApiUser(roleCode: 'security');
    }
}
