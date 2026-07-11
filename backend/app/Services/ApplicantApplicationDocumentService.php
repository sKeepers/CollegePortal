<?php

namespace App\Services;

use App\Models\ApplicantApplication;
use App\Models\ApplicantApplicationDocument;

class ApplicantApplicationDocumentService
{
    public const REQUIRED_DOCUMENTS_COUNT = 6;

    public function __construct(private readonly ApplicantDocumentRegistryService $registry)
    {
    }

    public function ensureDefaultDocuments(ApplicantApplication $application): void
    {
        $this->registry->syncLegacyDocumentTypes($application);
        $this->registry->ensureRegistry($application);
    }

    public function updateDocument(ApplicantApplication $application, string $type, array $payload): ApplicantApplicationDocument
    {
        $this->ensureDefaultDocuments($application);
        $document = $this->registry->documentByType($application, $type);

        $status = ($payload['is_received'] ?? false)
            ? ApplicantApplicationDocument::STATUS_RECEIVED
            : ApplicantApplicationDocument::STATUS_MISSING;

        $document->update([
            'status' => $status,
            'is_received' => (bool) ($payload['is_received'] ?? false),
            'received_at' => $payload['received_at'] ?? null,
            'number' => $payload['number'] ?? null,
            'comment' => $payload['comment'] ?? null,
            'source' => $payload['source'] ?? 'legacy_patch',
        ]);

        return $document->fresh(['documentType', 'files', 'receiver', 'verifier']);
    }

    public function completeness(ApplicantApplication $application): array
    {
        return $this->registry->stats($application);
    }
}
