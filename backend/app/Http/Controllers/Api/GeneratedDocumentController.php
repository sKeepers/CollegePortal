<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentEventResource;
use App\Http\Resources\GeneratedDocumentResource;
use App\Models\GeneratedDocument;
use App\Services\AuditLogService;
use App\Services\Documents\DocumentGenerationService;
use Illuminate\Http\Request;

class GeneratedDocumentController extends Controller
{
    public function __construct(private readonly DocumentGenerationService $documents)
    {
    }

    public function index()
    {
        return GeneratedDocumentResource::collection(GeneratedDocument::query()->with(['type', 'template'])->latest()->paginate(25));
    }

    public function show(GeneratedDocument $document): GeneratedDocumentResource
    {
        return new GeneratedDocumentResource($document->load(['type', 'template']));
    }

    public function preview(Request $request): array
    {
        $data = $request->validate([
            'document_type_code' => ['required', 'string'],
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'overrides' => ['array'],
        ]);
        AuditLogService::log('documents', 'preview', ['type' => 'DocumentType', 'id' => $data['document_type_code']]);

        return $this->documents->preview($data['document_type_code'], (int) $data['student_id'], $data['overrides'] ?? []);
    }

    public function generate(Request $request): GeneratedDocumentResource
    {
        $data = $request->validate([
            'document_type_code' => ['required', 'string'],
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'overrides' => ['array'],
        ]);

        return new GeneratedDocumentResource($this->documents->generate($data['document_type_code'], (int) $data['student_id'], $data['overrides'] ?? [])->load(['type', 'template']));
    }

    public function events(GeneratedDocument $document)
    {
        return DocumentEventResource::collection($document->events()->latest('created_at')->get());
    }

    public function downloadDocx(GeneratedDocument $document)
    {
        abort_unless($document->output_docx_path && is_file($document->output_docx_path), 404);
        AuditLogService::log('documents', 'download_docx', $document);

        return response()->download($document->output_docx_path, $document->registration_number.'.docx');
    }

    public function downloadPdf(GeneratedDocument $document)
    {
        abort_unless($document->output_pdf_path && is_file($document->output_pdf_path), 404, 'PDF недоступен.');
        AuditLogService::log('documents', 'download_pdf', $document);

        return response()->download($document->output_pdf_path, $document->registration_number.'.pdf');
    }

    public function issue(GeneratedDocument $document): GeneratedDocumentResource
    {
        return new GeneratedDocumentResource($this->documents->issue($document)->load(['type', 'template']));
    }

    public function cancel(Request $request, GeneratedDocument $document): GeneratedDocumentResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return new GeneratedDocumentResource($this->documents->cancel($document, $data['reason'])->load(['type', 'template']));
    }

    public function reprint(GeneratedDocument $document): GeneratedDocumentResource
    {
        return new GeneratedDocumentResource($this->documents->reprint($document)->load(['type', 'template']));
    }
}
