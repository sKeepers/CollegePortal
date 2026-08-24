<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Person;
use App\Models\Student;
use App\Support\Csv\CsvExport;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Выгрузка обязана оставлять след.
 *
 * Выгрузка — момент, когда данные покидают систему. Разбор 24.08.2026 показал,
 * что из семи выгрузок портала запись в журнал оставляли две: на боевом сервере
 * портал не смог бы ответить, кто унёс список студентов со СНИЛС и паспортными
 * данными, и не смог бы никогда — такую запись нельзя восстановить задним
 * числом.
 *
 * Проверяется здесь и обратное, причём дважды. В журнал попадает **счёт, а не
 * данные**: запись о выгрузке паспортов, содержащая паспорта, завела бы вторую
 * копию рядом с первой. И запись не появляется там, где выгрузки не было —
 * отказ по праву, оборванный обход, непрочитанный поток. Журнал, который
 * утверждает событие, которого не было, хуже отсутствующего: по нему первый же
 * разбор уйдёт не туда.
 */
class ExportLeavesATraceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_a_student_export_records_who_took_what(): void
    {
        $this->students();
        $user = $this->createApiUser(roleCode: 'admin');

        $response = $this->withApiAuth($user)->get('/api/students/export');
        $response->assertOk();
        // Поток выполняется только когда его читают: без этого тело выгрузки
        // не запускается вовсе, и след писать нечему.
        $response->streamedContent();

        $log = AuditLog::query()->where('action', 'csv_exported')->latest('id')->first();

        $this->assertNotNull($log, 'Выгрузка студентов не оставила следа в журнале.');
        $this->assertSame($user->id, $log->user_id, 'В следе не записано, кто выгружал.');
        $this->assertSame('api/students/export', $log->new_values['path'] ?? null);
        $this->assertSame(2, $log->new_values['rows'] ?? null, 'В следе должно стоять число выгруженных строк.');
    }

    public function test_the_trace_keeps_the_filter_but_not_the_search_text(): void
    {
        $this->students();

        $response = $this->withApiAuth($this->createApiUser(roleCode: 'admin'))
            ->get('/api/students/export?status=active&search=Иванов');
        $response->assertOk();
        $response->streamedContent();

        $log = AuditLog::query()->where('action', 'csv_exported')->latest('id')->firstOrFail();
        $filters = $log->new_values['filters'] ?? [];

        $this->assertSame('active', $filters['status'] ?? null, 'Отбор обязан остаться: без него видно число, но не видно, кого унесли.');
        $this->assertSame('[задан]', $filters['search'] ?? null, 'Строка поиска в журнал не пишется: по ней туда попала бы фамилия.');
        $this->assertStringNotContainsString('Иванов', json_encode($log->new_values, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Отказ — не выгрузка. Журнал, который пишет «выгрузил 593 строки» там, где
     * человека не пустили, хуже отсутствующего: он утверждает событие, которого
     * не было, и первый же разбор по нему уйдёт не туда.
     *
     * Заодно этот тест охраняет само право у маршрута: снимите
     * `permission:students.view` с выгрузки — и он покраснеет здесь, а не
     * когда-нибудь на боевом сервере. Проверено снятием.
     */
    public function test_a_refused_export_leaves_no_trace(): void
    {
        $this->students();

        $this->withApiAuth($this->createApiUser(roleCode: 'teacher'))
            ->get('/api/students/export')
            ->assertForbidden();

        $this->assertSame(0, AuditLog::query()->where('action', 'csv_exported')->count(), 'Отказ по праву оставил след выгрузки.');
    }

    /**
     * Пустая выгрузка — всё-таки выгрузка: файл с одним заголовком уехал, и
     * запись обязана это сказать честным нулём, а не отсутствием записи.
     */
    public function test_an_empty_export_is_recorded_as_a_plain_zero(): void
    {
        $response = $this->withApiAuth($this->createApiUser(roleCode: 'admin'))->get('/api/students/export');
        $response->assertOk();
        $response->streamedContent();

        $log = AuditLog::query()->where('action', 'csv_exported')->latest('id')->firstOrFail();

        $this->assertSame(0, $log->new_values['rows'] ?? null, 'Пустая выгрузка обязана записаться нулём строк.');
    }

    /**
     * Файл, не дошедший до конца, следа не оставляет.
     *
     * Запись стоит **после** обхода выборки — до него неизвестно число строк, —
     * поэтому обрыв на середине уносит и запись. Это осознанный размен: у
     * оборванной выгрузки нет и файла, а запись о полученных 593 строках там,
     * где ушло сорок, врала бы ровно в том числе, ради которого её заводили.
     */
    public function test_an_export_that_breaks_midway_leaves_no_trace(): void
    {
        $this->withApiAuth($this->createApiUser(roleCode: 'admin'));

        $response = CsvExport::download('broken.csv', ['Фамилия'], function (callable $row): void {
            $row(['Иванов']);

            throw new RuntimeException('обрыв на середине выборки');
        });

        // Поток пишет в стандартный вывод, и без буфера половина файла оседает
        // в выводе самого прогона.
        ob_start();

        try {
            $response->sendContent();
            $this->fail('Ожидалось, что обход выборки прервётся исключением.');
        } catch (RuntimeException) {
            // Так и задумано: проверяем, что после обрыва записи не появилось.
        } finally {
            ob_end_clean();
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'csv_exported')->count(), 'Оборванная выгрузка оставила след, будто файл ушёл целиком.');
    }

    /**
     * И самое тихое из всего: пока поток не прочитан, файла нет.
     *
     * Laravel возвращает `StreamedResponse` сразу, а тело выполняет при
     * отправке. Если бы запись стояла в месте вызова, а не внутри потока,
     * журнал пополнялся бы от одного лишь захода на маршрут.
     */
    public function test_an_unread_stream_is_not_an_export(): void
    {
        $this->students();
        $this->withApiAuth($this->createApiUser(roleCode: 'admin'));

        CsvExport::download('students.csv', ['Фамилия'], function (callable $row): void {
            $row(['Иванов']);
        });

        $this->assertSame(0, AuditLog::query()->where('action', 'csv_exported')->count(), 'След появился до того, как файл был отдан.');
    }

    private function students(): void
    {
        $group = Group::create(['name' => 'ИСП-101', 'specialty' => 'Искусство', 'course' => 1, 'year_start' => 2026]);

        foreach ([['Иванов', 'Иван'], ['Петрова', 'Мария']] as [$lastName, $firstName]) {
            $person = Person::create(['last_name' => $lastName, 'first_name' => $firstName, 'status' => 'active']);
            Student::create([
                'person_id' => $person->id,
                'last_name' => $lastName,
                'first_name' => $firstName,
                'group_id' => $group->id,
                'status' => 'active',
            ]);
        }
    }
}
