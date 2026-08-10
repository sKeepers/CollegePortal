<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Аудит 08.08.2026, находка 2: пункт меню виден, а страница не открывается.
 * Страница грузила справочники одним `Promise.all`, и единственный 403 отклонял
 * его весь. Лечение — во фронтенде (`services/referenceLoader.js`), но оно верно
 * только при одном условии: сам экран роли доступен, а отказ приходит именно по
 * справочникам.
 *
 * Тест закрепляет это условие. Если основной запрос экрана начнёт отвечать 403,
 * устойчивая загрузка справочников уже не спасёт — понадобится решение о правах,
 * и тест обязан об этом сказать.
 */
class RoleScreenAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Экраны из таблицы аудита: роль, основной запрос страницы и справочники,
     * которых роли не хватало.
     *
     * @return array<string, array{0:string,1:string,2:list<string>}>
     */
    public static function screens(): array
    {
        return [
            'admission: Группы' => ['admission', 'groups', ['teachers']],
            'teacher: Экзамены и ГИА' => ['teacher', 'exams', ['groups', 'subjects', 'teachers', 'classrooms', 'students']],
            'curator: Группы' => ['curator', 'groups', ['education-programs', 'teachers']],
            'hr: Преподаватели' => ['hr', 'teachers', ['subjects', 'schedule-lessons']],
            'security: Цифровые пропуска' => ['security', 'digital-identities', ['students', 'teachers', 'employees']],
        ];
    }

    /**
     * @param list<string> $references
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function test_screen_opens_for_its_role_even_when_references_are_denied(string $roleCode, string $screen, array $references): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWithRole($roleCode));

        $this->getJson('/api/'.$screen)->assertOk();

        // Хотя бы один справочник роли недоступен — иначе строка таблицы аудита
        // устарела и её надо убирать, а не носить за собой.
        $denied = array_filter(
            $references,
            fn (string $reference): bool => $this->getJson('/api/'.$reference)->status() === 403,
        );

        $this->assertNotEmpty(
            $denied,
            "Экран {$screen} роли {$roleCode} больше не упирается в справочники: строку аудита пора удалить.",
        );
    }

    /**
     * Четыре экрана «Учебной части 2» из той же таблицы аудита. Открытый
     * вопрос находки 2 — должна ли роль видеть образовательные программы и
     * специальности — владелец закрыл 10.08.2026: должна, она ведёт группы и
     * выпуск. Устойчивости к отказу этим экранам больше не нужно, нужен сам
     * доступ, поэтому проверяется он.
     *
     * @return array<string, array{0:string,1:string,2:list<string>}>
     */
    public static function resolvedScreens(): array
    {
        return [
            'study_records: Группы' => ['study_records', 'groups', ['education-programs']],
            'study_records: Учебные планы' => ['study_records', 'curricula', ['education-programs', 'specialties']],
            'study_records: Выпускники' => ['study_records', 'graduates', ['education-programs', 'specialties']],
            'study_records: ФРДО' => ['study_records', 'frdo-packages', ['education-programs']],
        ];
    }

    /**
     * @param list<string> $references
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('resolvedScreens')]
    public function test_screen_and_its_references_are_both_open_after_the_decision(string $roleCode, string $screen, array $references): void
    {
        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWithRole($roleCode));

        $this->getJson('/api/'.$screen)->assertOk();

        foreach ($references as $reference) {
            $this->getJson('/api/'.$reference)->assertOk(
                "Справочник {$reference} снова закрыт для роли {$roleCode}: экран {$screen} останется с пустым списком.",
            );
        }
    }

    private function userWithRole(string $roleCode): User
    {
        $role = Role::query()->where('code', $roleCode)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'is_active' => true,
            'password' => Hash::make(Str::random(16)),
        ]);
    }
}
