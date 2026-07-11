<?php

namespace App\Services;

use App\Models\ApplicantApplication;
use App\Models\ApplicantApplicationDocument;
use App\Models\ApplicantDocumentFile;
use App\Models\ReferenceItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApplicantDocumentRegistryService
{
    public const CATALOG_CODE = 'applicant_document_types';
    public const STORAGE_DIR = 'applicant-documents';

    /** @return Collection<int, ReferenceItem> */
    public function documentTypes(bool $requiredOnly = false): Collection
    {
        return ReferenceService::catalog(self::CATALOG_CODE)
            ->filter(fn (ReferenceItem $item) => ! $requiredOnly || (bool) ($item->metadata['required'] ?? false))
            ->values();
    }

    /** @return Collection<int, ApplicantApplicationDocument> */
    public function ensureRegistry(ApplicantApplication $application, string $source = 'registry'): Collection
    {
        foreach ($this->documentTypes() as $type) {
            $document = $application->documents()
                ->where(function ($query) use ($type) {
                    $query->where('document_type_id', $type->id)
                        ->orWhere(function ($query) use ($type) {
                            $query->whereNull('document_type_id')
                                ->where('type', $type->code);
                        });
                })
                ->first();

            if ($document) {
                $document->update([
                    'document_type_id' => $type->id,
                    'type' => $type->code,
                    'title' => $type->name,
                ]);

                continue;
            }

            $application->documents()->create([
                'document_type_id' => $type->id,
                'type' => $type->code,
                'title' => $type->name,
                'status' => ApplicantApplicationDocument::STATUS_MISSING,
                'is_received' => false,
                'source' => $source,
            ]);
        }

        return $application->documents()->with(['documentType', 'files', 'receiver', 'verifier'])->get();
    }

    public function syncLegacyDocumentTypes(ApplicantApplication $application): void
    {
        $types = $this->documentTypes()->keyBy('code');

        foreach ($application->documents()->whereNull('document_type_id')->get() as $document) {
            $code = $document->type === 'consent' ? 'personal_data_consent' : $document->type;
            $type = $types->get($code);
            if (! $type) {
                continue;
            }

            $document->update([
                'document_type_id' => $type->id,
                'type' => $type->code,
                'title' => $type->name,
                'status' => $document->is_received ? ApplicantApplicationDocument::STATUS_RECEIVED : ApplicantApplicationDocument::STATUS_MISSING,
                'source' => $document->source ?: 'legacy',
            ]);
        }
    }

    public function stats(ApplicantApplication $application): array
    {
        $documents = $application->documents()->with('documentType')->get();
        $requiredTypes = $this->documentTypes(requiredOnly: true);
        $requiredIds = $requiredTypes->pluck('id')->all();
        $requiredDocuments = $documents->whereIn('document_type_id', $requiredIds);
        $received = $requiredDocuments->filter(fn (ApplicantApplicationDocument $document) => in_array($document->status, ApplicantApplicationDocument::COMPLETE_STATUSES, true));
        $verified = $requiredDocuments->filter(fn (ApplicantApplicationDocument $document) => $document->status === ApplicantApplicationDocument::STATUS_VERIFIED);

        $required = $requiredTypes->count();
        $count = $received->count();
        $verifiedCount = $verified->count();
        $complete = $required > 0 && $count >= $required;
        $verifiedComplete = $required > 0 && $verifiedCount >= $required;
        $status = $count === 0 ? 'no_documents' : ($verifiedComplete ? 'verified_complete' : ($complete ? 'complete' : 'incomplete'));

        return [
            'documents_count' => $count,
            'required_documents_count' => $required,
            'documents_missing_count' => max(0, $required - $count),
            'documents_complete' => $complete,
            'documents_verified_complete' => $verifiedComplete,
            'documents_status' => $status,
        ];
    }

    public function receive(ApplicantApplication $application, string $typeCode, User $user, array $payload = []): ApplicantApplicationDocument
    {
        $document = $this->documentByType($application, $typeCode);
        $status = $payload['status'] ?? ApplicantApplicationDocument::STATUS_RECEIVED;
        if (! in_array($status, [ApplicantApplicationDocument::STATUS_RECEIVED, ApplicantApplicationDocument::STATUS_UNDER_REVIEW], true)) {
            $status = ApplicantApplicationDocument::STATUS_RECEIVED;
        }

        $document->update([
            'status' => $status,
            'is_received' => true,
            'received_at' => $payload['received_at'] ?? now()->toDateString(),
            'received_by' => $user->id,
            'rejection_reason' => null,
            'comment' => $payload['comment'] ?? $document->comment,
            'source' => $payload['source'] ?? 'manual',
        ]);

        return $document->fresh(['documentType', 'files', 'receiver', 'verifier']);
    }

    public function verify(ApplicantApplication $application, ApplicantApplicationDocument $document, User $user, array $payload = []): ApplicantApplicationDocument
    {
        $this->assertBelongsTo($application, $document);
        $document->update([
            'status' => ApplicantApplicationDocument::STATUS_VERIFIED,
            'is_received' => true,
            'received_at' => $document->received_at ?: now()->toDateString(),
            'received_by' => $document->received_by ?: $user->id,
            'verified_at' => now(),
            'verified_by' => $user->id,
            'rejection_reason' => null,
            'comment' => $payload['comment'] ?? $document->comment,
        ]);

        return $document->fresh(['documentType', 'files', 'receiver', 'verifier']);
    }

    public function reject(ApplicantApplication $application, ApplicantApplicationDocument $document, User $user, string $reason, ?string $comment = null): ApplicantApplicationDocument
    {
        $this->assertBelongsTo($application, $document);
        $document->update([
            'status' => ApplicantApplicationDocument::STATUS_REJECTED,
            'is_received' => false,
            'verified_at' => now(),
            'verified_by' => $user->id,
            'rejection_reason' => $reason,
            'comment' => $comment ?? $document->comment,
        ]);

        return $document->fresh(['documentType', 'files', 'receiver', 'verifier']);
    }

    public function update(ApplicantApplication $application, ApplicantApplicationDocument $document, array $payload): ApplicantApplicationDocument
    {
        $this->assertBelongsTo($application, $document);
        $allowed = array_intersect_key($payload, array_flip(['status', 'received_at', 'rejection_reason', 'comment']));
        if (isset($allowed['status'])) {
            $allowed['is_received'] = in_array($allowed['status'], ApplicantApplicationDocument::COMPLETE_STATUSES, true);
        }
        $document->update($allowed);

        return $document->fresh(['documentType', 'files', 'receiver', 'verifier']);
    }

    public function upload(ApplicantApplication $application, string $typeCode, UploadedFile $file, User $user): ApplicantDocumentFile
    {
        $document = $this->receive($application, $typeCode, $user, ['status' => ApplicantApplicationDocument::STATUS_UNDER_REVIEW]);
        $metadata = $document->documentType?->metadata ?? [];
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        $allowed = array_map('strtolower', $metadata['allowed_extensions'] ?? ['pdf', 'jpg', 'jpeg', 'png', 'webp']);

        if (! in_array($extension, $allowed, true)) {
            throw ValidationException::withMessages(['file' => 'Недопустимое расширение файла.']);
        }

        $maxBytes = (int) (($metadata['max_size_mb'] ?? 10) * 1024 * 1024);
        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['file' => 'Файл превышает допустимый размер.']);
        }

        $mime = (string) $file->getMimeType();
        if (! $this->allowedMime($mime)) {
            throw ValidationException::withMessages(['file' => 'Недопустимый MIME-тип файла.']);
        }

        $storedName = (string) Str::uuid().'.'.$extension;
        $storedPath = self::STORAGE_DIR.'/'.$application->id.'/'.$document->id.'/'.$storedName;
        Storage::disk('local')->putFileAs(dirname($storedPath), $file, basename($storedPath));
        $contents = Storage::disk('local')->get($storedPath);

        return $document->files()->create([
            'original_name' => basename($file->getClientOriginalName()),
            'stored_path' => $storedPath,
            'mime_type' => $mime,
            'size_bytes' => $file->getSize(),
            'checksum_sha256' => hash('sha256', $contents),
            'uploaded_by' => $user->id,
        ]);
    }

    public function deleteDocument(ApplicantApplication $application, ApplicantApplicationDocument $document): void
    {
        $this->assertBelongsTo($application, $document);
        foreach ($document->files as $file) {
            Storage::disk('local')->delete($file->stored_path);
        }
        $document->delete();
    }

    public function deleteFile(ApplicantApplication $application, ApplicantApplicationDocument $document, ApplicantDocumentFile $file): void
    {
        $this->assertBelongsTo($application, $document);
        if ((int) $file->applicant_application_document_id !== (int) $document->id) {
            abort(404);
        }
        Storage::disk('local')->delete($file->stored_path);
        $file->delete();
    }

    public function documentByType(ApplicantApplication $application, string $typeCode): ApplicantApplicationDocument
    {
        $this->ensureRegistry($application);
        $code = $typeCode === 'consent' ? 'personal_data_consent' : $typeCode;
        return $application->documents()
            ->where(fn ($query) => $query
                ->whereHas('documentType', fn ($query) => $query->where('code', $code))
                ->orWhere('type', $code))
            ->firstOrFail();
    }

    private function assertBelongsTo(ApplicantApplication $application, ApplicantApplicationDocument $document): void
    {
        if ((int) $document->applicant_application_id !== (int) $application->id) {
            abort(404);
        }
    }

    private function allowedMime(string $mime): bool
    {
        return in_array($mime, [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp',
        ], true);
    }
}
