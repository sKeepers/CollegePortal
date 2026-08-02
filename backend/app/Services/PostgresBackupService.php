<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\File;
use RuntimeException;

class PostgresBackupService
{
    public function __construct(private readonly PostgresBackupProcessRunner $processRunner)
    {
    }

    /** @return list<array{id: string, name: string, created_at: string, size: int, type: string}> */
    public function snapshots(): array
    {
        $this->ensurePostgres();
        $directory = $this->directory();

        if (! File::isDirectory($directory)) {
            return [];
        }

        return collect(File::files($directory))
            ->filter(fn ($file) => preg_match('/^(manual|emergency)-[0-9]{8}-[0-9]{6}-[a-f0-9-]{36}\.dump$/', $file->getFilename()))
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->map(fn ($file) => [
                'id' => $file->getFilename(),
                'name' => $file->getFilename(),
                'created_at' => now()->setTimestamp($file->getMTime())->toIso8601String(),
                'size' => $file->getSize(),
                'type' => str_starts_with($file->getFilename(), 'emergency-') ? 'emergency' : 'manual',
            ])
            ->values()
            ->all();
    }

    /** @return array{id: string, name: string, created_at: string, size: int, type: string} */
    public function create(User $actor, bool $emergency = false): array
    {
        $this->ensurePostgres();
        File::ensureDirectoryExists($this->directory(), 0700, true);

        $filename = sprintf('%s-%s-%s.dump', $emergency ? 'emergency' : 'manual', now()->format('Ymd-His'), (string) str()->uuid());
        $path = $this->directory().DIRECTORY_SEPARATOR.$filename;
        $this->runDump($path);

        $snapshot = $this->snapshot($filename);
        AuditLogService::log('database_backup', $emergency ? 'emergency_snapshot_created' : 'snapshot_created', ['type' => 'database_snapshot', 'id' => $filename], null, [
            'name' => $snapshot['name'],
            'size' => $snapshot['size'],
            'type' => $snapshot['type'],
        ], user: $actor);

        return $snapshot;
    }

    /** @return array{snapshot: array{id: string, name: string, created_at: string, size: int, type: string}, emergency_snapshot: array{id: string, name: string, created_at: string, size: int, type: string}} */
    public function restore(string $snapshotId, User $actor): array
    {
        $snapshot = $this->snapshot($snapshotId);
        $emergencySnapshot = $this->create($actor, true);
        $this->runRestore($this->directory().DIRECTORY_SEPARATOR.$snapshot['id']);

        AuditLogService::log('database_backup', 'snapshot_restored', ['type' => 'database_snapshot', 'id' => $snapshot['id']], null, [
            'name' => $snapshot['name'],
            'emergency_snapshot' => $emergencySnapshot['name'],
        ], user: $actor);

        return ['snapshot' => $snapshot, 'emergency_snapshot' => $emergencySnapshot];
    }

    /** @return array{id: string, name: string, created_at: string, size: int, type: string} */
    private function snapshot(string $snapshotId): array
    {
        if (! preg_match('/^(manual|emergency)-[0-9]{8}-[0-9]{6}-[a-f0-9-]{36}\.dump$/', $snapshotId)) {
            throw new RuntimeException('Архив не найден.');
        }

        $path = $this->directory().DIRECTORY_SEPARATOR.$snapshotId;
        if (! File::isFile($path)) {
            throw new RuntimeException('Архив не найден.');
        }

        return [
            'id' => $snapshotId,
            'name' => $snapshotId,
            'created_at' => now()->setTimestamp(File::lastModified($path))->toIso8601String(),
            'size' => File::size($path),
            'type' => str_starts_with($snapshotId, 'emergency-') ? 'emergency' : 'manual',
        ];
    }

    private function runDump(string $path): void
    {
        $result = $this->processRunner->run([
            'pg_dump', '--format=custom', '--file='.$path, '--host='.$this->connection('host'), '--port='.(string) $this->connection('port'), '--username='.$this->connection('username'), $this->connection('database'),
        ], $this->environment(), $this->timeout());

        if (! $result->successful()) {
            File::delete($path);
            throw new RuntimeException('Не удалось создать архив PostgreSQL.');
        }
    }

    private function runRestore(string $path): void
    {
        $result = $this->processRunner->run([
            'pg_restore', '--clean', '--if-exists', '--no-owner', '--no-privileges', '--host='.$this->connection('host'), '--port='.(string) $this->connection('port'), '--username='.$this->connection('username'), '--dbname='.$this->connection('database'), $path,
        ], $this->environment(), $this->timeout());

        if (! $result->successful()) {
            throw new RuntimeException('Восстановление PostgreSQL не выполнено. Исходное состояние сохранено в аварийном архиве.');
        }
    }

    private function ensurePostgres(): void
    {
        if (config('database.default') !== 'pgsql') {
            throw new RuntimeException('Полное архивирование поддерживается только для PostgreSQL.');
        }
    }

    private function directory(): string
    {
        return config('backups.postgresql.path');
    }

    private function timeout(): int
    {
        return config('backups.postgresql.timeout');
    }

    private function connection(string $key): string|int
    {
        return config('database.connections.pgsql.'.$key);
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return ['PGPASSWORD' => (string) $this->connection('password')];
    }
}
