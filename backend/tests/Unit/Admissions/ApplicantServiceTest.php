<?php

namespace Tests\Unit\Admissions;

use App\Models\Admissions\Applicant;
use App\Models\AuditLog;
use App\Models\Person;
use App\Services\Admissions\ApplicantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unit-тесты foundation-сервиса абитуриентов.
 */
class ApplicantServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_links_existing_person_by_reliable_identifier(): void
    {
        $person = Person::query()->create([
            'last_name' => 'Тестов',
            'first_name' => 'Абитуриент',
            'birth_date' => '2007-01-10',
            'email' => 'applicant@example.test',
            'snils' => '112-233-445 95',
            'snils_hash' => hash('sha256', '11223344595'),
            'status' => 'active',
        ]);

        $applicant = app(ApplicantService::class)->createFoundation([
            'last_name' => 'Тестов',
            'first_name' => 'Абитуриент',
            'birth_date' => '2007-01-10',
            'email' => 'APPLICANT@example.test',
            'snils' => '11223344595',
        ]);

        $this->assertSame($person->id, $applicant->person_id);
        $this->assertSame(1, Person::query()->count());
        $this->assertSame(1, Applicant::query()->count());
    }

    public function test_applicant_creates_person_when_no_duplicate_exists(): void
    {
        $applicant = app(ApplicantService::class)->createFoundation([
            'last_name' => 'Новый',
            'first_name' => 'Абитуриент',
            'middle_name' => 'Тестович',
            'birth_date' => '2008-03-14',
            'phone' => '+7 (900) 100-20-30',
            'email' => 'new-applicant@example.test',
            'snils' => '112-233-445 95',
        ]);

        $person = $applicant->person;

        $this->assertNotNull($person);
        $this->assertNotEmpty($person->uuid);
        $this->assertSame('79001002030', $person->phone);
        $this->assertSame('new-applicant@example.test', $person->email);
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'Admissions',
            'action' => 'applicant_created',
            'entity_type' => 'Applicant',
            'entity_id' => $applicant->id,
        ]);
    }

    public function test_ambiguous_person_duplicates_are_not_merged_automatically(): void
    {
        foreach (['first@example.test', 'second@example.test'] as $email) {
            Person::query()->create([
                'last_name' => 'Дубль',
                'first_name' => 'Абитуриент',
                'birth_date' => '2007-05-05',
                'phone' => '79001112233',
                'email' => $email,
                'status' => 'active',
            ]);
        }

        $this->expectException(ValidationException::class);

        app(ApplicantService::class)->createFoundation([
            'last_name' => 'Дубль',
            'first_name' => 'Абитуриент',
            'birth_date' => '2007-05-05',
            'phone' => '+7 900 111-22-33',
            'snils' => '112-233-445 95',
        ]);
    }

    public function test_existing_active_applicant_is_reused_for_same_person(): void
    {
        $service = app(ApplicantService::class);

        $first = $service->createFoundation([
            'last_name' => 'Один',
            'first_name' => 'Профиль',
            'birth_date' => '2006-11-01',
            'email' => 'one-profile@example.test',
            'snils' => '112-233-445 95',
        ]);

        $second = $service->createFoundation([
            'last_name' => 'Один',
            'first_name' => 'Профиль',
            'birth_date' => '2006-11-01',
            'email' => 'one-profile@example.test',
            'snils' => '112-233-445 95',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Applicant::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'applicant_existing_reused')->exists());
    }
}
