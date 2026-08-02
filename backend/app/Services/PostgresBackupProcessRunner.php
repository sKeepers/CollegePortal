<?php

namespace App\Services;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

class PostgresBackupProcessRunner
{
    /** @param list<string> $command @param array<string, string> $environment */
    public function run(array $command, array $environment, int $timeout): ProcessResult
    {
        return Process::env($environment)->timeout($timeout)->run($command);
    }
}
