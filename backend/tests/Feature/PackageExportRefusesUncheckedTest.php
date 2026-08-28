<?php

namespace Tests\Feature;

use App\Models\EducationProgram;
use App\Models\Specialty;
use App\Support\Packages\PackageExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CompletesStudentCard;
use Tests\TestCase;

/**
 * Пакет выгружается файлом только после проверки.
 *
 * Разбор выгрузок 24.08.2026: у ФИС и у ФРДО отметка «выгружено» требовала
 * пройденной проверки и говорила «выгрузка заблокирована», а соседняя кнопка
 * «скачать файлом» не проверяла ничего. Человек, которому портал только что
 * отказал, нажимал соседнюю кнопку и получал тот же пакет с невалидными
 * записями — и в системе оставалось «не выгружено», то есть след события
 * терялся вместе с запретом.
 *
 * Здесь закреплено и обратное, и оно важнее запрета: **уже выгруженный пакет
 * скачивается повторно**. Условие отметки пропускает только `ready`, и если бы
 * его скопировали на файл, портал начал бы мешать там, где вреда нет: файл
 * теряют, отправляют не тому, просят второй экземпляр.
 */
class PackageExportRefusesUncheckedTest extends TestCase
{
    use CompletesStudentCard;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withApiAuth();
    }

    public function test_an_unchecked_frdo_package_is_not_exported_as_a_file(): void
    {
        $packageId = $this->frdoPackage();

        $this->getJson("/api/frdo-packages/{$packageId}/export.csv")
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', fn (string $message) => str_contains($message, 'ещё не проверялся'));

        $this->getJson("/api/frdo-packages/{$packageId}/export.json")
            ->assertStatus(422);
    }

    public function test_a_checked_frdo_package_is_exported_and_stays_exportable_afterwards(): void
    {
        $packageId = $this->frdoPackage();

        $this->postJson("/api/frdo-packages/{$packageId}/validate")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');

        $this->get("/api/frdo-packages/{$packageId}/export.csv")->assertOk();

        $this->postJson("/api/frdo-packages/{$packageId}/mark-exported")
            ->assertOk()
            ->assertJsonPath('data.status', 'exported');

        // Ради этой строки правило и написано отдельно от условия отметки:
        // выгруженный пакет обязан скачиваться снова.
        $this->get("/api/frdo-packages/{$packageId}/export.csv")->assertOk();
    }

    public function test_an_unchecked_fis_package_is_not_exported_as_a_file(): void
    {
        $specialty = Specialty::create(['code' => '53.02.03', 'name' => 'Инструментальное исполнительство', 'education_level' => 'СПО']);
        $program = EducationProgram::create(['specialty_id' => $specialty->id, 'name' => 'ППССЗ Инструментальное исполнительство', 'year_start' => 2023, 'study_form' => 'Очная', 'study_years' => 4, 'is_active' => true]);

        $packageId = $this->postJson('/api/fis-packages', ['package_type' => 'admission', 'year' => 2026, 'education_program_id' => $program->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->getJson("/api/fis-packages/{$packageId}/export.csv")
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', fn (string $message) => str_contains($message, 'ФИС ГИА'));

        $this->getJson("/api/fis-packages/{$packageId}/export.json")
            ->assertStatus(422);
    }

    /**
     * Само правило, без похода через маршрут: состояния перечислены явно, чтобы
     * следующий видел границу целиком, а не выводил её из двух примеров.
     */
    public function test_the_rule_names_exactly_which_states_are_refused(): void
    {
        foreach (['draft', 'validation_failed'] as $refused) {
            try {
                PackageExport::assertExportable($refused, 'ФРДО');
                $this->fail("Состояние «{$refused}» обязано отказывать.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['ready', 'exported', 'archived', null] as $allowed) {
            PackageExport::assertExportable($allowed, 'ФРДО');
            $this->addToAssertionCount(1);
        }
    }

    private function frdoPackage(): int
    {
        $specialty = Specialty::create(['code' => '53.02.04', 'name' => 'Вокальное искусство', 'education_level' => 'СПО', 'qualification' => 'Артист-вокалист, преподаватель']);
        $program = EducationProgram::create(['specialty_id' => $specialty->id, 'name' => 'ППССЗ Вокальное искусство', 'year_start' => 2023, 'study_form' => 'Очная', 'study_years' => 4, 'is_active' => true]);

        return (int) $this->postJson('/api/frdo-packages', [
            'graduation_year' => 2027,
            'education_program_id' => $program->id,
            'name' => 'ФРДО 2027',
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    }
}
