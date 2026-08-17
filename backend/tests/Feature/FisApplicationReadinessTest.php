<?php

namespace Tests\Feature;

use App\Models\Admissions\AdmissionApplication;
use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\FisApplicationReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Готовность заявления к выгрузке в ФИС.
 *
 * Сами правила — что заявлению нужен документ, удостоверяющий личность, выбранная
 * программа, конкурс и прочее — проверяются тестами сборки пакета, и **намеренно
 * не дублируются здесь**: служба гоняет тот же сборщик, а не свою копию правил.
 * Дублирующий тест закреплял бы вторую копию, которой не существует.
 *
 * Здесь проверяется то, что добавлено: проводка от маршрута до сборщика,
 * отсутствие записи в базу и формулировка отказа.
 */
class FisApplicationReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withApiAuth();
    }

    public function test_a_missing_application_is_not_found(): void
    {
        $this->getJson('/api/admissions/applications/999999/fis-readiness')->assertNotFound();
    }

    /**
     * Заявления, которого нет среди зарегистрированных, объяснение обязано
     * касаться самого заявления. Прежний текст говорил «за такой-то год нет
     * зарегистрированных заявлений приёмной комиссии» — и человек шёл проверять
     * кампанию вместо карточки.
     */
    public function test_the_reason_speaks_about_the_application_not_the_campaign(): void
    {
        $answer = app(FisApplicationReadinessService::class)->check(
            new AdmissionApplication(['admission_year' => (int) date('Y')]),
        );

        $this->assertFalse($answer['ready']);
        $this->assertNotEmpty($answer['blockers'], 'Готовности нет, а причин не названо.');

        $texts = implode(' ', array_column($answer['blockers'], 'message'));
        $this->assertStringContainsString('Заявление не зарегистрировано', $texts);
        $this->assertStringNotContainsString('нет зарегистрированных заявлений приёмной комиссии', $texts);
    }

    /** У каждого препятствия есть код и человеческое объяснение. */
    public function test_every_blocker_is_explained(): void
    {
        $answer = app(FisApplicationReadinessService::class)->check(
            new AdmissionApplication(['admission_year' => (int) date('Y')]),
        );

        foreach ($answer['blockers'] as $blocker) {
            $this->assertNotEmpty($blocker['code']);
            $this->assertNotEmpty($blocker['message']);
        }
    }

    /** Проверка гоняет сборщик в память: пакета после неё оставаться не должно. */
    public function test_it_writes_nothing(): void
    {
        app(FisApplicationReadinessService::class)->check(
            new AdmissionApplication(['admission_year' => (int) date('Y')]),
        );

        $this->assertSame(0, FisOutboundPackage::query()->count());
    }

    /** Без года кампании сборщик обязан сказать именно об этом. */
    public function test_an_application_without_a_year_says_so(): void
    {
        $answer = app(FisApplicationReadinessService::class)->check(new AdmissionApplication());

        $this->assertFalse($answer['ready']);
        $this->assertSame('admission_year_missing', $answer['blockers'][0]['code']);
    }
}
