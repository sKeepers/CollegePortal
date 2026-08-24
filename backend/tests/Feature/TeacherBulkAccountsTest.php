<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Bulk\TeacherBulkService;
use App\Services\Import\TeacherImportHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Массовая выдача учётных записей преподавателям.
 *
 * Данные вымышленные, счёт настоящий: репетиция первого сентября 24.08.2026
 * насчитала около 105 шагов руками, и **шестьдесят из них — один и тот же сброс
 * пароля преподавателю**. Здесь закреплено то, ради чего выдача заводилась:
 * пароль возвращается человеку **один раз** и не попадает ни в журнал аудита,
 * ни куда-либо ещё.
 */
class TeacherBulkAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_counts_and_creates_nothing(): void
    {
        $teacher = $this->makeTeacher();

        $report = app(TeacherBulkService::class)->preview('issue_accounts', ['ids' => [$teacher->id]]);

        $this->assertSame(1, $report['selected']);
        $this->assertSame(1, $report['will_change']);
        $this->assertSame([], $report['credentials'], 'предпросмотр не выдаёт паролей: он ничего не создаёт');
        $this->assertNull($teacher->refresh()->user_id);
        $this->assertSame(0, User::count());
    }

    public function test_apply_issues_an_account_and_returns_the_password_once(): void
    {
        $teacher = $this->makeTeacher();

        $report = app(TeacherBulkService::class)->apply('issue_accounts', ['ids' => [$teacher->id]], Request::create('/'));

        $this->assertSame(1, $report['changed']);
        $this->assertCount(1, $report['credentials']);
        $this->assertNotEmpty($report['credentials'][0]['login']);
        $this->assertNotEmpty($report['credentials'][0]['password']);
        $this->assertNotNull($teacher->refresh()->user_id);
    }

    public function test_the_password_never_reaches_the_audit_log(): void
    {
        $teacher = $this->makeTeacher();

        $report = app(TeacherBulkService::class)->apply('issue_accounts', ['ids' => [$teacher->id]], Request::create('/'));
        $password = $report['credentials'][0]['password'];

        $written = AuditLog::query()->get()->map(fn (AuditLog $log): string => json_encode($log->toArray(), JSON_UNESCAPED_UNICODE))->implode(' ');

        $this->assertStringNotContainsString($password, $written, 'пароль в журнале аудита — это пароль, который останется навсегда');
    }

    public function test_a_teacher_who_already_has_an_account_is_skipped_not_duplicated(): void
    {
        $teacher = $this->makeTeacher();
        app(TeacherBulkService::class)->apply('issue_accounts', ['ids' => [$teacher->id]], Request::create('/'));

        $again = app(TeacherBulkService::class)->apply('issue_accounts', ['ids' => [$teacher->id]], Request::create('/'));

        $this->assertSame(1, $again['skipped']);
        $this->assertSame([], $again['credentials']);
        $this->assertSame(1, User::count());
    }

    public function test_an_unknown_action_is_refused_rather_than_guessed(): void
    {
        $teacher = $this->makeTeacher();

        $report = app(TeacherBulkService::class)->preview('archive_selected', ['ids' => [$teacher->id]]);

        $this->assertSame(1, $report['errors']);
    }

    /**
     * «Создать учётную запись = да» больше не заводит запись молча.
     *
     * Один текст и одно поведение на три загрузчика; почему именно так — в
     * `AccountProvisioningService::ACCOUNTS_ARE_ISSUED_SEPARATELY`.
     */
    public function test_the_import_refuses_to_create_an_account_silently(): void
    {
        $errors = app(TeacherImportHandler::class)->businessValidationErrors([
            'auto_account' => true,
            'last_name' => 'Ковалёв',
            'first_name' => 'Пётр',
        ]);

        $this->assertArrayHasKey('auto_account', $errors);
        $this->assertStringContainsString('массовым действием', $errors['auto_account'][0]);
    }

    public function test_the_import_says_nothing_when_the_column_says_no(): void
    {
        $errors = app(TeacherImportHandler::class)->businessValidationErrors([
            'auto_account' => false,
            'last_name' => 'Ковалёв',
            'first_name' => 'Пётр',
        ]);

        $this->assertSame([], $errors);
    }

    private function makeTeacher(): Teacher
    {
        return Teacher::create([
            'last_name' => 'Ковалёв',
            'first_name' => 'Пётр',
            'middle_name' => 'Ильич',
            'email' => 'teacher'.uniqid().'@example.test',
            'status' => 'active',
        ]);
    }
}
