<?php

namespace Tests\Feature;

use App\Models\EducationProgram;
use App\Models\Group;
use App\Models\Person;
use App\Models\Specialty;
use App\Models\Student;
use App\Models\StudentCertificate;
use App\Services\Graduation\Exceptions\StrictReportingRecordIsNeverDeleted;
use App\Services\SettingService;
use App\Services\Students\StudentCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Справки студентам: нумерация, снимок и отказ вместо пустого места.
 *
 * Данные вымышленные. Проверяется то, что уходит на бумагу и подписывается
 * директором, поэтому три свойства важнее остальных: номер не повторяется и не
 * пропускается, напечатанное не меняется задним числом, а недостающее поле
 * останавливает выдачу, а не оставляет пробел в подписанном документе.
 */
class StudentCertificateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_student_gets_two_certificates_with_consecutive_numbers(): void
    {
        // Решение владельца 28.08.2026: «обычно делают две копии справки с
        // разным номером, следующий студент будет две справки с номерами 1910
        // и 1911». Значит счётчик двигается на два, а не выдаёт один номер двум
        // листам.
        $first = $this->makeStudent(course: 1);
        $second = $this->makeStudent(course: 1, lastName: 'Второй');

        $issued = app(StudentCertificateService::class)->issue($first);
        $this->assertSame([1910, 1911], $issued->pluck('number')->all());

        $next = app(StudentCertificateService::class)->issue($second);
        $this->assertSame([1912, 1913], $next->pluck('number')->all());
    }

    public function test_the_numbering_continues_the_paper_register(): void
    {
        // В файле владельца последний занятый номер 1909 при 1181 номере без
        // пропусков. Портал начинает с 1910, а не с единицы: иначе один номер
        // оказался бы в двух местах сразу.
        $this->assertSame(1910, (int) SettingService::value('certificates', 'next_number'));
    }

    public function test_an_issued_number_is_never_handed_out_again(): void
    {
        // Настройку человек может понизить по ошибке, а выданный номер уже на
        // бумаге у студента. Отсчёт идёт от большего из двух.
        $student = $this->makeStudent(course: 1);
        app(StudentCertificateService::class)->issue($student, copies: 1);

        SettingService::updateMany([
            ['group' => 'certificates', 'key' => 'next_number', 'value' => 1],
        ]);

        $again = app(StudentCertificateService::class)->issue($this->makeStudent(course: 1, lastName: 'Третий'), copies: 1);

        $this->assertSame([1911], $again->pluck('number')->all());
    }

    public function test_a_missing_field_stops_the_certificate_and_names_itself(): void
    {
        $student = $this->makeStudent(course: 1);
        $student->forceFill(['enrollment_order_date' => null])->save();

        try {
            app(StudentCertificateService::class)->issue($student->refresh());
            $this->fail('Справка без даты приказа выписываться не должна.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('дата приказа о зачислении', $e->getMessage());
        }

        $this->assertSame(0, StudentCertificate::count());
    }

    public function test_the_first_course_carries_no_transfer_order(): void
    {
        $certificate = app(StudentCertificateService::class)
            ->issue($this->makeStudent(course: 1), copies: 1)
            ->first();

        $this->assertNull($certificate->transfer_order_number);
        $this->assertNull($certificate->transfer_order_date);
    }

    public function test_the_second_course_carries_the_transfer_order_from_settings(): void
    {
        // Приказ о переводе один на весь колледж и меняется раз в год, поэтому
        // он в настройках, а не в карточке студента.
        $certificate = app(StudentCertificateService::class)
            ->issue($this->makeStudent(course: 2), copies: 1)
            ->first();

        $this->assertSame('96', $certificate->transfer_order_number);
        $this->assertSame('2026-07-01', $certificate->transfer_order_date->toDateString());
    }

    public function test_the_dates_of_study_come_from_the_group_and_the_programme(): void
    {
        // Образец владельца: набор 2026, срок 3.8 — начало 01.09.2026, окончание
        // 30.06.2030. Дробная часть округляется вверх, потому что учебный год
        // заканчивается в июне следующего календарного.
        $certificate = app(StudentCertificateService::class)
            ->issue($this->makeStudent(course: 1), copies: 1)
            ->first();

        $this->assertSame('2026-09-01', $certificate->study_start->toDateString());
        $this->assertSame('2030-06-30', $certificate->study_end->toDateString());
    }

    public function test_what_was_printed_does_not_change_when_the_student_does(): void
    {
        // Ради этого справка и хранит снимок: студента переведут на курс выше,
        // а выданная справка обязана остаться такой, какой её подписали.
        $student = $this->makeStudent(course: 2);
        $certificate = app(StudentCertificateService::class)->issue($student, copies: 1)->first();

        $student->forceFill(['course' => 3, 'last_name' => 'Переименованный'])->save();

        $certificate->refresh();
        $this->assertSame(2, $certificate->course);
        $this->assertStringContainsString('Вымышленный', $certificate->full_name);
    }

    public function test_a_certificate_is_never_deleted(): void
    {
        $certificate = app(StudentCertificateService::class)
            ->issue($this->makeStudent(course: 1), copies: 1)
            ->first();

        $this->expectException(StrictReportingRecordIsNeverDeleted::class);
        $certificate->delete();
    }

    public function test_the_register_is_ordered_by_number(): void
    {
        $service = app(StudentCertificateService::class);
        $service->issue($this->makeStudent(course: 1), copies: 2);
        $service->issue($this->makeStudent(course: 2, lastName: 'Второй'), copies: 2);

        $this->assertSame([1910, 1911, 1912, 1913], $service->registry()->pluck('number')->all());
    }

    public function test_more_copies_than_a_person_would_ask_for_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(StudentCertificateService::class)->issue($this->makeStudent(course: 1), copies: 99);
    }

    private function makeStudent(int $course, string $lastName = 'Первый'): Student
    {
        $specialty = Specialty::firstOrCreate(
            ['code' => '51.02.01'],
            ['name' => 'Народное художественное творчество'],
        );

        $program = EducationProgram::firstOrCreate(
            ['name' => 'Хореографическое творчество'],
            [
                'specialty_id' => $specialty->id,
                'year_start' => 2026,
                'study_form' => 'Очная',
                'study_years' => 3.8,
                'is_active' => true,
            ],
        );

        $group = Group::firstOrCreate(
            ['name' => 'Хореографическое творчество, набор 2026'],
            [
                'specialty' => 'Народное художественное творчество',
                'education_program_id' => $program->id,
                'year_start' => 2026,
            ],
        );

        $person = Person::create([
            'uuid' => (string) Str::uuid(),
            'last_name' => $lastName,
            'first_name' => 'Вымышленный',
            'middle_name' => 'Студентович',
            'birth_date' => '2008-05-14',
            'status' => 'active',
        ]);

        return Student::create([
            'person_id' => $person->id,
            'group_id' => $group->id,
            'course' => $course,
            'last_name' => $lastName,
            'first_name' => 'Вымышленный',
            'middle_name' => 'Студентович',
            'birth_date' => '2008-05-14',
            'status' => 'active',
            'enrollment_order_number' => '124',
            'enrollment_order_date' => '2026-08-28',
        ]);
    }
}
