<?php

namespace App\Services;

use App\Models\ApplicantApplication;
use App\Models\ApplicantApplicationDocument;

class ApplicantApplicationDocumentService
{
    private const DEFAULT_DOCUMENTS = [
        'passport' => 'Паспорт',
        'education_document' => 'Документ об образовании',
        'snils' => 'СНИЛС',
        'consent' => 'Согласие на обработку персональных данных',
        'photo' => 'Фотография',
        'medical_certificate' => 'Медицинская справка',
    ];

    public function ensureDefaultDocuments(ApplicantApplication $application): void
    {
        foreach (self::DEFAULT_DOCUMENTS as $type => $title) {
            $application->documents()->firstOrCreate(
                ['type' => $type],
                ['title' => $title],
            );
        }
    }

    public function updateDocument(ApplicantApplication $application, string $type, array $payload): ApplicantApplicationDocument
    {
        $this->ensureDefaultDocuments($application);

        $document = $application->documents()->where('type', $type)->firstOrFail();
        $document->update([
            'is_received' => $payload['is_received'],
            'received_at' => $payload['received_at'] ?? null,
            'number' => $payload['number'] ?? null,
            'comment' => $payload['comment'] ?? null,
        ]);

        return $document;
    }
}
