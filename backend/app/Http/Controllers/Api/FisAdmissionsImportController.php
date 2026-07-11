<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImportJobResource;
use App\Models\ImportJob;
use App\Services\AuditLogService;
use App\Services\Import\FisAdmissionsImportHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class FisAdmissionsImportController extends Controller
{
    public function __construct(private readonly FisAdmissionsImportHandler $handler)
    {
    }

    public function analyze(Request $request): JsonResponse
    {
        $job = $this->storeJob($request, 'analyze');
        $analysis = $this->handler->analyzePath(Storage::disk('local')->path($job->stored_path));
        $job->update([
            'status' => 'analyzed',
            'metadata' => $analysis,
            'headers' => $analysis['headers'],
            'total_rows' => $analysis['row_count'],
        ]);

        AuditLogService::log('import', 'fis_analyze', $job, null, ['file_hash' => $job->file_hash, 'rows' => $analysis['row_count']], $request);

        return response()->json(['data' => new ImportJobResource($job->fresh()->load('user'))], Response::HTTP_CREATED);
    }

    public function dryRun(Request $request): JsonResponse
    {
        $job = $this->storeJob($request, 'dry_run');
        try {
            $summary = $this->handler->dryRunJob($job);
        } catch (RuntimeException $exception) {
            $job->update(['status' => 'validation_failed', 'errors' => [['reason' => $exception->getMessage()]], 'error_count' => 1]);
            throw $exception;
        }

        $job->update([
            'status' => $summary['critical_errors'] > 0 || $summary['ambiguous_duplicates'] > 0 || $summary['unresolved_competitions'] > 0 ? 'validation_failed' : 'validated',
            'headers' => $summary['headers'],
            'metadata' => $summary,
            'preview_rows' => $summary['preview_rows'],
            'validation_errors' => $summary['errors'],
            'warnings' => $summary['warnings'],
            'errors' => $summary['errors'],
            'result' => $summary,
            'total_rows' => $summary['total_rows'],
            'created_count' => $summary['applications_to_create'],
            'updated_count' => $summary['applications_to_update'],
            'error_count' => $summary['critical_errors'] + $summary['ambiguous_duplicates'],
        ]);

        AuditLogService::log('import', 'fis_dry_run', $job, null, ['file_hash' => $job->file_hash, 'summary' => $this->safeAuditSummary($summary)], $request);

        return response()->json(['data' => new ImportJobResource($job->fresh()->load('user'))], Response::HTTP_CREATED);
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'job_id' => ['required_without:file', 'integer', 'exists:import_jobs,id'],
            'file' => ['required_without:job_id', 'file', 'mimes:xls,xlsx', 'max:20480'],
        ]);

        $job = isset($data['job_id'])
            ? ImportJob::query()->where('source', FisAdmissionsImportHandler::SOURCE)->findOrFail($data['job_id'])
            : $this->storeJob($request, 'apply');

        $summary = $this->handler->applyJob($job);
        $job->update([
            'mode' => 'apply',
            'status' => 'completed',
            'metadata' => $summary,
            'preview_rows' => $summary['preview_rows'],
            'validation_errors' => [],
            'warnings' => $summary['warnings'],
            'errors' => [],
            'result' => $summary,
            'total_rows' => $summary['total_rows'],
            'created_count' => $summary['created_count'],
            'updated_count' => $summary['updated_count'],
            'skipped_count' => 0,
            'error_count' => 0,
        ]);

        AuditLogService::log('import', 'fis_apply', $job, null, ['file_hash' => $job->file_hash, 'summary' => $this->safeAuditSummary($summary)], $request);

        return response()->json(['data' => new ImportJobResource($job->fresh()->load('user'))]);
    }

    public function show(ImportJob $importJob): ImportJobResource
    {
        abort_unless($importJob->source === FisAdmissionsImportHandler::SOURCE, Response::HTTP_NOT_FOUND);

        return new ImportJobResource($importJob->load('user'));
    }

    private function storeJob(Request $request, string $mode): ImportJob
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:20480'],
        ]);

        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'xls');
        $directory = 'imports/fis_uploads';
        $storedPath = $directory.'/'.$hash.'.'.$extension;

        Storage::disk('local')->makeDirectory($directory);
        $saved = Storage::disk('local')->put($storedPath, file_get_contents($file->getRealPath()));
        if (! $saved || ! Storage::disk('local')->exists($storedPath)) {
            throw new RuntimeException('Не удалось временно сохранить файл ФИС для обработки.');
        }

        return ImportJob::create([
            'user_id' => $request->user()?->id,
            'data_type' => 'applicants',
            'source' => FisAdmissionsImportHandler::SOURCE,
            'mode' => $mode,
            'status' => 'uploaded',
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'file_hash' => $hash,
        ]);
    }

    private function safeAuditSummary(array $summary): array
    {
        return collect($summary)->only([
            'mode', 'total_rows', 'valid_rows', 'applications', 'unique_persons', 'found_persons',
            'new_persons', 'applications_to_create', 'applications_to_update', 'created_count',
            'updated_count', 'ambiguous_duplicates', 'unique_competitions', 'exact_matched_competitions',
            'unresolved_competitions', 'critical_errors',
        ])->all();
    }
}
