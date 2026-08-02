<?php

namespace Tests\Unit;

use App\Services\PostgresBackupProcessRunner;
use App\Services\PostgresBackupService;
use App\Models\User;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Mockery;
use Tests\TestCase;

class PostgresBackupServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/postgresql-backups');
        File::deleteDirectory($this->directory);
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql', [
            'host' => 'postgres', 'port' => 5432, 'database' => 'college_portal', 'username' => 'college_user', 'password' => 'secret-password',
        ]);
        config()->set('backups.postgresql.path', $this->directory);
        config()->set('backups.postgresql.timeout', 60);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_create_uses_argument_array_and_keeps_password_out_of_command(): void
    {
        $result = Mockery::mock(ProcessResult::class);
        $result->shouldReceive('successful')->andReturnTrue();
        $runner = Mockery::mock(PostgresBackupProcessRunner::class);
        $runner->shouldReceive('run')->once()->withArgs(function (array $command, array $environment, int $timeout): bool {
            $this->assertSame('pg_dump', $command[0]);
            $this->assertContains('--format=custom', $command);
            $this->assertSame(['PGPASSWORD' => 'secret-password'], $environment);
            $this->assertSame(60, $timeout);
            $this->assertNotContains('secret-password', $command);
            File::ensureDirectoryExists(dirname(substr($command[2], strlen('--file='))));
            File::put(substr($command[2], strlen('--file=')), 'archive');

            return true;
        })->andReturn($result);

        $snapshot = (new PostgresBackupService($runner))->create(new User(['id' => 1]));

        $this->assertSame('manual', $snapshot['type']);
        $this->assertSame(7, $snapshot['size']);
    }

    public function test_restore_creates_emergency_archive_before_pg_restore(): void
    {
        File::ensureDirectoryExists($this->directory);
        $snapshotId = 'manual-20260802-120000-11111111-1111-1111-1111-111111111111.dump';
        File::put($this->directory.DIRECTORY_SEPARATOR.$snapshotId, 'selected archive');
        $result = Mockery::mock(ProcessResult::class);
        $result->shouldReceive('successful')->andReturnTrue();
        $commands = [];
        $runner = Mockery::mock(PostgresBackupProcessRunner::class);
        $runner->shouldReceive('run')->twice()->andReturnUsing(function (array $command) use (&$commands, $result) {
            $commands[] = $command;
            if ($command[0] === 'pg_dump') {
                File::put(substr($command[2], strlen('--file=')), 'emergency archive');
            }

            return $result;
        });

        $restore = (new PostgresBackupService($runner))->restore($snapshotId, new User(['id' => 1]));

        $this->assertSame('pg_dump', $commands[0][0]);
        $this->assertSame('pg_restore', $commands[1][0]);
        $this->assertContains('--clean', $commands[1]);
        $this->assertSame('emergency', $restore['emergency_snapshot']['type']);
    }
}
