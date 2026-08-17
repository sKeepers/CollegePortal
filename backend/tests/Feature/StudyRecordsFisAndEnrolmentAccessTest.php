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
 * Решение владельца 17.08.2026: учебная часть ведёт справочники ФИС и зачисляет.
 *
 * Найдено от жалобы «вошёл под тем, кто работает со студентами, и не нашёл
 * раздела ФИС». Замер показал больше: у роли не было **ни одного** права ни по
 * ФИС, ни по приёму — контингент она ведёт, а данные для него завести не может.
 *
 * Тест закрепляет **обе половины решения**: что дано и что не дано. Вторая
 * половина важнее: формирование и отправка пакетов в государственную систему
 * осталась у администратора, и расширить это молча не должно получиться.
 */
class StudyRecordsFisAndEnrolmentAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->withApiAuth($this->userWithRole('study_records'));
    }

    public function test_it_sees_the_fis_section(): void
    {
        $this->getJson('/api/fis-packages')->assertOk();
    }

    public function test_it_sees_fis_diagnostics(): void
    {
        $this->getJson('/api/fis/outbound/spec-info')->assertOk();
    }

    /**
     * Применение справочника требует пары прав, и обе половины у роли теперь есть.
     * Файла в запросе нет, поэтому ответ — отказ проверки, а не `403`: именно это
     * и означает, что права хватило.
     */
    public function test_it_may_apply_fis_dictionaries(): void
    {
        $this->postJson('/api/fis/dictionaries/apply', [])->assertStatus(422);
    }

    public function test_it_sees_admission_applications(): void
    {
        $this->getJson('/api/admissions/applications')->assertOk();
    }

    /** Зачисление разрешено. Заявления в базе нет, поэтому `404`, а не `403`. */
    public function test_it_may_enrol(): void
    {
        $this->postJson('/api/applicant-applications/1/enroll', [])->assertStatus(404);
    }

    /**
     * Чего роль не получила. Пакет в ФИС — обращение к государственной системе,
     * и он остался за администратором и приёмной комиссией.
     *
     * Выгрузки и отправки пакета здесь нет намеренно: их маршруты привязаны к
     * модели, и на несуществующем идентификаторе ответ приходит `404` **раньше**
     * проверки права. Доказать отказ таким запросом нельзя — он отказывает не по
     * той причине. Отсутствие права закрыто первой строкой: без `fis.outbound.create`
     * пакет не создать, а значит и выгружать нечего.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function refused(): array
    {
        return [
            'создание пакета ФИС' => ['post', 'fis/outbound/packages'],
            'заведение абитуриента' => ['post', 'admissions/applicants'],
            'правка заявления' => ['patch', 'admissions/applications/1'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function test_it_is_refused_where_the_owner_kept_the_line(string $method, string $path): void
    {
        $this->json(strtoupper($method), '/api/'.$path, [])->assertStatus(403);
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
