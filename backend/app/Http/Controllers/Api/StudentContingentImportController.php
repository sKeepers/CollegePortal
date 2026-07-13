<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImportJobResource;
use App\Models\ImportJob;
use App\Services\AuditLogService;
use App\Services\Import\StudentContingentDocImportHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StudentContingentImportController extends Controller
{
    public function __construct(private readonly StudentContingentDocImportHandler $handler)
    {
    }

    public function analyze(Request $request): JsonResponse
    {
        $job = $this->storeJob($request, 'analyze');
        $summary = $this->handler->analyzePath(Storage::disk('local')->path($job->stored_path), $job);
        $this->updateJobFromSummary($job, $summary, 'analyzed');

        AuditLogService::log('import', 'student_contingent_analyze', $job, null, [
            'file_hash' => $job->file_hash,
            'summary' => $this->safeAuditSummary($summary),
        ], $request);

        return response()->json(['data' => new ImportJobResource($job->fresh()->load('user'))], Response::HTTP_CREATED);
    }

    public function dryRun(Request $request): JsonResponse
    {
        $job = $this->storeJob($request, 'dry_run');
        $summary = $this->handler->dryRunJob($job);
        $status = ($summary['error_rows'] ?? 0) > 0 || ($summary['review_required'] ?? 0) > 0 || ($summary['blockers'] ?? 0) > 0
            ? 'validation_failed'
            : 'validated';
        $this->updateJobFromSummary($job, $summary, $status);

        AuditLogService::log('import', 'student_contingent_dry_run', $job, null, [
            'file_hash' => $job->file_hash,
            'summary' => $this->safeAuditSummary($summary),
        ], $request);

        return response()->json(['data' => new ImportJobResource($job->fresh()->load('user'))], Response::HTTP_CREATED);
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'job_id' => ['required', 'integer', 'exists:import_jobs,id'],
        ]);

        $job = ImportJob::query()->where('source', StudentContingentDocImportHandler::SOURCE)->findOrFail($data['job_id']);
        $summary = $this->handler->applyJob($job);
        $this->updateJobFromSummary($job, $summary, 'completed');
        $job->update(['mode' => 'apply']);

        AuditLogService::log('import', 'student_contingent_apply', $job, null, [
            'file_hash' => $job->file_hash,
            'summary' => $this->safeAuditSummary($summary),
        ], $request);

        return response()->json(['data' => new ImportJobResource($job->fresh()->load('user'))]);
    }

    public function show(ImportJob $importJob): ImportJobResource
    {
        abort_unless($importJob->source === StudentContingentDocImportHandler::SOURCE, Response::HTTP_NOT_FOUND);

        return new ImportJobResource($importJob->load('user'));
    }

    public function review(ImportJob $importJob)
    {
        abort_unless($importJob->source === StudentContingentDocImportHandler::SOURCE, Response::HTTP_NOT_FOUND);
        $path = data_get($importJob->metadata, 'artifacts.review_xlsx');
        abort_unless($path && Storage::disk('local')->exists($path), Response::HTTP_NOT_FOUND);

        return Storage::disk('local')->download($path, 'student-contingent-review.xlsx');
    }

    private function storeJob(Request $request, string $mode): ImportJob
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:doc,txt', 'max:20480'],
        ]);

        $file = $request->file('file');
        $hash = hash_file('sha256', $file->getRealPath());
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'doc');
        $directory = 'imports/students/uploads';
        $storedPath = $directory.'/'.$hash.'.'.$extension;

        Storage::disk('local')->makeDirectory($directory);
        $saved = Storage::disk('local')->put($storedPath, file_get_contents($file->getRealPath()));
        if (! $saved || ! Storage::disk('local')->exists($storedPath)) {
            throw new RuntimeException('Не удалось временно сохранить файл контингента студентов.');
        }

        return ImportJob::create([
            'user_id' => $request->user()?->id,
            'data_type' => 'students',
            'source' => StudentContingentDocImportHandler::SOURCE,
            'mode' => $mode,
            'status' => 'uploaded',
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'file_hash' => $hash,
        ]);
    }

    private function updateJobFromSummary(ImportJob $job, array $summary, string $status): void
    {
        $job->update([
            'status' => $status,
            'headers' => $summary['headers'] ?? [],
            'metadata' => $summary,
            'preview_rows' => $summary['preview_rows'] ?? [],
            'validation_errors' => $summary['errors'] ?? [],
            'warnings' => $summary['warnings'] ?? [],
            'errors' => $summary['errors'] ?? [],
            'result' => $this->safeResult($summary),
            'total_rows' => $summary['total_rows'] ?? 0,
            'created_count' => $summary['created_count'] ?? 0,
            'updated_count' => $summary['updated_count'] ?? 0,
            'skipped_count' => $summary['skipped_count'] ?? 0,
            'error_count' => ($summary['error_rows'] ?? 0) + ($summary['review_required'] ?? 0),
        ]);
    }

    private function safeResult(array $summary): array
    {
        return collect($summary)->except(['errors', 'warnings'])->all();
    }

    private function safeAuditSummary(array $summary): array
    {
        return collect($summary)->only([
            'mode', 'source', 'total_rows', 'valid_rows', 'review_required', 'error_rows', 'blockers',
            'created_count', 'updated_count', 'skipped_count', 'section_types', 'unknown_specialties_count', 'unknown_groups_count',
        ])->all();
    }
}
