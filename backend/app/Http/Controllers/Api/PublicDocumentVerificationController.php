<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeneratedDocument;
use App\Services\AuditLogService;
use App\Services\Documents\DocumentVerificationService;

class PublicDocumentVerificationController extends Controller
{
    public function __invoke(string $publicId, DocumentVerificationService $verification): array
    {
        $document = GeneratedDocument::query()->with('type')->where('verification_public_id', $publicId)->firstOrFail();
        AuditLogService::log('documents', 'public_verify', ['type' => 'GeneratedDocument', 'id' => $document->id], null, ['status' => $document->status]);

        return $verification->publicPayload($document);
    }
}
