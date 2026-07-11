<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApplicantApplicationDocumentResource;
use App\Models\ApplicantApplication;
use App\Models\ApplicantApplicationDocument;
use App\Models\ApplicantDocumentFile;
use App\Services\ApplicantDocumentRegistryService;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApplicantDocumentController extends Controller
{
    public function __construct(private readonly ApplicantDocumentRegistryService $registry)
    {
    }

    public function index(ApplicantApplication $applicantApplication): JsonResponse
    {
        $documents = $this->registry->ensureRegistry($applicantApplication);
        $stats = $this->registry->stats($applicantApplication);

        return response()->json([
            'data' => ApplicantApplicationDocumentResource::collection($documents)->resolve(),
            'meta' => $stats,
        ]);
    }

    public function receive(Request $request, ApplicantApplication $applicantApplication, string $type): ApplicantApplicationDocumentResource
    {
        $document = $this->registry->receive($applicantApplication, $type, $request->user(), $request->validate([
            'received_at' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'in:received,under_review'],
        ]));

        AuditLogService::log('Admissions', 'document_received', $document, null, [
            'document_type' => $document->type,
            'status' => $document->status,
        ], $request);

        return new ApplicantApplicationDocumentResource($document);
    }

    public function upload(Request $request, ApplicantApplication $applicantApplication, string $type): ApplicantApplicationDocumentResource
    {
        $request->validate(['file' => ['required', 'file']]);
        $file = $this->registry->upload($applicantApplication, $type, $request->file('file'), $request->user());
        $document = $file->document->fresh(['documentType', 'files', 'receiver', 'verifier']);

        AuditLogService::log('Admissions', 'document_file_uploaded', $document, null, [
            'document_type' => $document->type,
            'file_id' => $file->id,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size_bytes' => $file->size_bytes,
            'checksum_sha256' => $file->checksum_sha256,
        ], $request);

        return new ApplicantApplicationDocumentResource($document);
    }

    public function verify(Request $request, ApplicantApplication $applicantApplication, ApplicantApplicationDocument $document): ApplicantApplicationDocumentResource
    {
        $payload = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $document = $this->registry->verify($applicantApplication, $document, $request->user(), $payload);

        AuditLogService::log('Admissions', 'document_verified', $document, null, [
            'document_type' => $document->type,
            'status' => $document->status,
        ], $request);

        return new ApplicantApplicationDocumentResource($document);
    }

    public function reject(Request $request, ApplicantApplication $applicantApplication, ApplicantApplicationDocument $document): ApplicantApplicationDocumentResource
    {
        $payload = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $document = $this->registry->reject($applicantApplication, $document, $request->user(), $payload['rejection_reason'], $payload['comment'] ?? null);

        AuditLogService::log('Admissions', 'document_rejected', $document, null, [
            'document_type' => $document->type,
            'status' => $document->status,
            'rejection_reason' => $payload['rejection_reason'],
        ], $request);

        return new ApplicantApplicationDocumentResource($document);
    }

    public function update(Request $request, ApplicantApplication $applicantApplication, ApplicantApplicationDocument $document): ApplicantApplicationDocumentResource
    {
        $payload = $request->validate([
            'status' => ['nullable', 'in:missing,received,under_review,verified,rejected'],
            'received_at' => ['nullable', 'date'],
            'rejection_reason' => ['nullable', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $old = $document->only(['status', 'received_at', 'rejection_reason', 'comment']);
        $document = $this->registry->update($applicantApplication, $document, $payload);

        AuditLogService::log('Admissions', 'document_updated', $document, $old, [
            'document_type' => $document->type,
            'status' => $document->status,
            'received_at' => $document->received_at?->toDateString(),
        ], $request);

        return new ApplicantApplicationDocumentResource($document);
    }

    public function destroy(Request $request, ApplicantApplication $applicantApplication, ApplicantApplicationDocument $document): JsonResponse
    {
        $documentType = $document->type;
        $this->registry->deleteDocument($applicantApplication, $document);
        AuditLogService::log('Admissions', 'document_deleted', ['type' => 'ApplicantApplicationDocument', 'id' => $document->id], null, [
            'document_type' => $documentType,
        ], $request);

        return response()->json(null, 204);
    }

    public function download(ApplicantApplication $applicantApplication, ApplicantApplicationDocument $document, ApplicantDocumentFile $file): StreamedResponse
    {
        if ((int) $document->applicant_application_id !== (int) $applicantApplication->id || (int) $file->applicant_application_document_id !== (int) $document->id) {
            abort(404);
        }

        if (! Storage::disk('local')->exists($file->stored_path)) {
            abort(404);
        }

        return Storage::disk('local')->download($file->stored_path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyFile(Request $request, ApplicantApplication $applicantApplication, ApplicantApplicationDocument $document, ApplicantDocumentFile $file): JsonResponse
    {
        $this->registry->deleteFile($applicantApplication, $document, $file);
        AuditLogService::log('Admissions', 'document_file_deleted', $document, null, [
            'file_id' => $file->id,
            'document_type' => $document->type,
        ], $request);

        return response()->json(null, 204);
    }
}
