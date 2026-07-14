<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DocumentTemplateResource;
use App\Models\DocumentTemplate;
use App\Models\DocumentType;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DocumentTemplateController extends Controller
{
    public function index()
    {
        return DocumentTemplateResource::collection(DocumentTemplate::query()->with('type')->latest()->paginate(25));
    }

    public function store(Request $request): DocumentTemplateResource
    {
        $data = $request->validate([
            'document_type_id' => ['required', 'exists:document_types,id'],
            'name' => ['required', 'string', 'max:255'],
            'version' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
        ]);

        $template = DocumentTemplate::query()->create($data + [
            'status' => 'draft',
            'source_format' => 'docx',
            'template_path' => 'document-templates/draft/'.uniqid('template_', true).'.docx',
            'output_formats' => ['docx', 'pdf'],
            'created_by' => $request->user()?->id,
        ]);

        AuditLogService::log('documents', 'template_created', $template);

        return new DocumentTemplateResource($template->load('type'));
    }

    public function show(DocumentTemplate $documentTemplate): DocumentTemplateResource
    {
        return new DocumentTemplateResource($documentTemplate->load('type'));
    }

    public function update(Request $request, DocumentTemplate $documentTemplate): DocumentTemplateResource
    {
        abort_if($documentTemplate->status === 'active', 422, 'Опубликованный шаблон нельзя изменять напрямую.');
        $documentTemplate->update($request->validate(['name' => ['sometimes', 'string'], 'notes' => ['nullable', 'string']]));

        return new DocumentTemplateResource($documentTemplate->refresh()->load('type'));
    }

    public function publish(Request $request, DocumentTemplate $documentTemplate): DocumentTemplateResource
    {
        DB::transaction(function () use ($request, $documentTemplate): void {
            DocumentTemplate::query()
                ->where('document_type_id', $documentTemplate->document_type_id)
                ->where('status', 'active')
                ->update(['status' => 'archived']);
            $documentTemplate->update(['status' => 'active', 'published_by' => $request->user()?->id, 'published_at' => now()]);
        });
        AuditLogService::log('documents', 'template_published', $documentTemplate);

        return new DocumentTemplateResource($documentTemplate->refresh()->load('type'));
    }

    public function archive(DocumentTemplate $documentTemplate): DocumentTemplateResource
    {
        $documentTemplate->update(['status' => 'archived']);
        AuditLogService::log('documents', 'template_archived', $documentTemplate);

        return new DocumentTemplateResource($documentTemplate->refresh()->load('type'));
    }

    public function upload(Request $request, DocumentTemplate $documentTemplate): DocumentTemplateResource
    {
        abort_if($documentTemplate->status === 'active', 422, 'Опубликованный шаблон нельзя изменять напрямую.');
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:docx']]);
        $path = $request->file('file')->store('document-templates', 'local');
        $documentTemplate->update(['template_path' => $path]);
        AuditLogService::log('documents', 'template_uploaded', $documentTemplate);

        return new DocumentTemplateResource($documentTemplate->refresh()->load('type'));
    }
}
