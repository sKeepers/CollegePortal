<?php

namespace App\Services\Documents;

use App\Models\DocumentEvent;
use App\Models\DocumentTemplate;
use App\Models\DocumentType;
use App\Models\GeneratedDocument;
use App\Models\Student;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentGenerationService
{
    public function __construct(
        private readonly DocumentVariableResolver $variables,
        private readonly DocumentNumberingService $numbering,
        private readonly DocumentRenderService $render,
        private readonly DocumentPdfService $pdf,
        private readonly DocumentVerificationService $verification,
    ) {
    }

    public function preview(string $typeCode, int $studentId, array $overrides = []): array
    {
        $type = DocumentType::query()->where('code', $typeCode)->firstOrFail();
        $student = Student::query()->findOrFail($studentId);
        $resolved = $this->variables->resolveStudentEnrollmentCertificate($student, $overrides);
        $template = $this->activeTemplate($type);

        return [
            'type' => $type,
            'template' => $template,
            'variables' => $resolved['variables'],
            'missing' => $resolved['missing'],
            'warnings' => $resolved['warnings'],
            'can_generate' => count($resolved['missing']) === 0 && $template !== null,
            'preview_html' => $this->previewHtml($resolved['variables']),
        ];
    }

    public function generate(string $typeCode, int $studentId, array $overrides = []): GeneratedDocument
    {
        return DB::transaction(function () use ($typeCode, $studentId, $overrides): GeneratedDocument {
            $preview = $this->preview($typeCode, $studentId, $overrides);
            if (! $preview['can_generate']) {
                throw new RuntimeException('Невозможно сформировать документ: есть недостающие данные или нет активного шаблона.');
            }

            /** @var DocumentType $type */
            $type = $preview['type'];
            /** @var DocumentTemplate $template */
            $template = $preview['template'];
            $verification = $this->verification->issueToken();
            $number = $this->numbering->next($type);
            $uuid = (string) Str::uuid();
            $dir = storage_path('app/private/generated-documents/'.now()->format('Y').'/'.$type->code.'/'.$uuid);
            $variables = array_merge($preview['variables'], [
                'document.number' => $number,
                'document.issue_date' => now()->format('d.m.Y'),
                'verification.url' => url('/verify/document/'.$verification['public_id']),
            ]);
            $docx = $dir.'/document.docx';
            $pdf = $dir.'/document.pdf';
            $this->render->renderDocx($variables, $docx);
            $pdfResult = $this->pdf->convertDocxToPdf($docx, $pdf);

            $document = GeneratedDocument::query()->create([
                'uuid' => $uuid,
                'document_type_id' => $type->id,
                'document_template_id' => $template->id,
                'subject_type' => 'student',
                'subject_id' => $studentId,
                'registration_number' => $number,
                'issue_date' => now()->toDateString(),
                'status' => 'generated',
                'output_docx_path' => $docx,
                'output_pdf_path' => $pdfResult['available'] ? $pdf : null,
                'payload_snapshot' => $variables,
                'payload_hash' => hash('sha256', json_encode($variables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'verification_token_hash' => $verification['hash'],
                'verification_public_id' => $verification['public_id'],
                'generated_by' => request()->user()?->id,
            ]);

            $this->event($document, 'generated', ['pdf' => $pdfResult['message']]);
            AuditLogService::log('documents', 'generated', $document, null, ['registration_number' => $number]);

            return $document->refresh();
        });
    }

    public function issue(GeneratedDocument $document): GeneratedDocument
    {
        $document->update(['status' => 'issued', 'issued_by' => request()->user()?->id, 'issued_at' => now()]);
        $this->event($document, 'issued');
        AuditLogService::log('documents', 'issued', $document);

        return $document->refresh();
    }

    public function cancel(GeneratedDocument $document, string $reason): GeneratedDocument
    {
        $document->update(['status' => 'cancelled', 'cancelled_by' => request()->user()?->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
        $this->event($document, 'cancelled', ['reason' => $reason]);
        AuditLogService::log('documents', 'cancelled', $document);

        return $document->refresh();
    }

    public function reprint(GeneratedDocument $document): GeneratedDocument
    {
        $document->increment('reprint_count');
        $document->refresh();
        $this->event($document, 'reprinted', ['count' => $document->reprint_count]);
        AuditLogService::log('documents', 'reprinted', $document);

        return $document->refresh();
    }

    private function activeTemplate(DocumentType $type): ?DocumentTemplate
    {
        return DocumentTemplate::query()->where('document_type_id', $type->id)->where('status', 'active')->latest('published_at')->first();
    }

    private function event(GeneratedDocument $document, string $type, array $metadata = []): void
    {
        DocumentEvent::query()->create(['generated_document_id' => $document->id, 'event_type' => $type, 'actor_id' => request()->user()?->id, 'metadata' => $metadata]);
    }

    private function previewHtml(array $variables): string
    {
        return '<p><strong>Справка</strong></p><p>'.e((string) ($variables['student.full_name'] ?? '')).' обучается в '.e((string) ($variables['organization.short_name'] ?? '')).'.</p>';
    }
}
