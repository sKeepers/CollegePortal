<?php

namespace App\Services\Admissions;

use App\Models\Admissions\AdmissionDocumentFile;
use App\Models\Admissions\AdmissionApplication;
use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;
use App\Models\User;
use App\Repositories\Admissions\AdmissionDocumentFileRepository;
use App\Services\AuditLogService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdmissionDocumentFileService
{
    private const MAX_BYTES = 15 * 1024 * 1024;

    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    private const ALLOWED_MIME = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
    ];

    private const CATEGORIES = ['main_spread', 'registration', 'attachment', 'front_side', 'back_side', 'other'];

    public function __construct(
        private readonly AdmissionDocumentFileRepository $files,
        private readonly AdmissionDocumentAudit $audit,
        private readonly AdmissionApplicationDocumentService $applicationDocuments,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function uploadIdentity(IdentityDocument $document, UploadedFile $file, array $payload = [], ?User $actor = null): AdmissionDocumentFile
    {
        $this->assertActiveIdentity($document);
        $fileInfo = $this->validateFile($file);
        $hash = $this->fileHash($file);

        if ($this->files->activeDuplicateForIdentityDocument($document->id, $hash)) {
            throw ValidationException::withMessages(['file' => 'Такой файл уже загружен для документа.']);
        }

        $storedPath = $this->store($file, $fileInfo['extension'], "admissions/documents/applicant-{$document->applicant_id}/identity-{$document->id}");

        $applicationId = $this->applicationId($payload['application_id'] ?? null, $document->applicant_id);

        $model = $document->files()->create([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $document->applicant_id,
            'application_id' => $applicationId,
            'category' => $this->category($payload['category'] ?? 'other'),
            'original_name' => basename($file->getClientOriginalName()),
            'generated_name' => basename($storedPath),
            'storage_path' => $storedPath,
            'mime_type' => $fileInfo['mime'],
            'extension' => $fileInfo['extension'],
            'size_bytes' => $file->getSize(),
            'sha256' => $hash,
            'uploaded_by' => $actor?->id,
            'uploaded_at' => now(),
        ]);

        AuditLogService::log('Admissions', 'identity_document_file_uploaded', $model, null, $this->audit->file($model), user: $actor);

        return $model->load(['identityDocument', 'uploader']);
    }

    /** @param array<string, mixed> $payload */
    public function uploadEducation(EducationDocument $document, UploadedFile $file, array $payload = [], ?User $actor = null): AdmissionDocumentFile
    {
        $this->assertActiveEducation($document);
        $fileInfo = $this->validateFile($file);
        $hash = $this->fileHash($file);

        if ($this->files->activeDuplicateForEducationDocument($document->id, $hash)) {
            throw ValidationException::withMessages(['file' => 'Такой файл уже загружен для документа.']);
        }

        $storedPath = $this->store($file, $fileInfo['extension'], "admissions/documents/applicant-{$document->applicant_id}/education-{$document->id}");

        $applicationId = $this->applicationId($payload['application_id'] ?? null, $document->applicant_id);

        $model = $document->files()->create([
            'uuid' => (string) Str::uuid(),
            'applicant_id' => $document->applicant_id,
            'application_id' => $applicationId,
            'category' => $this->category($payload['category'] ?? 'other'),
            'original_name' => basename($file->getClientOriginalName()),
            'generated_name' => basename($storedPath),
            'storage_path' => $storedPath,
            'mime_type' => $fileInfo['mime'],
            'extension' => $fileInfo['extension'],
            'size_bytes' => $file->getSize(),
            'sha256' => $hash,
            'uploaded_by' => $actor?->id,
            'uploaded_at' => now(),
        ]);

        AuditLogService::log('Admissions', 'education_document_file_uploaded', $model, null, $this->audit->file($model), user: $actor);

        return $model->load(['educationDocument', 'uploader']);
    }

    public function findForDownload(int $id, ?User $actor = null): AdmissionDocumentFile
    {
        $file = $this->files->find($id);
        abort_if(! $file, 404);

        if (! Storage::disk('local')->exists($file->storage_path)) {
            abort(404, 'Файл документа не найден в private storage.');
        }

        AuditLogService::log('Admissions', 'admission_document_file_downloaded', $file, null, $this->audit->file($file), user: $actor);

        return $file;
    }

    public function archive(int $id, ?User $actor = null): void
    {
        $file = $this->files->find($id);
        abort_if(! $file, 404);

        if ($file->identityDocument && $this->applicationDocuments->isIdentityLinkedToRegisteredApplication($file->identityDocument)) {
            throw ValidationException::withMessages(['file' => 'Файл документа зарегистрированного заявления нельзя архивировать.']);
        }
        if ($file->educationDocument && $this->applicationDocuments->isEducationLinkedToRegisteredApplication($file->educationDocument)) {
            throw ValidationException::withMessages(['file' => 'Файл документа зарегистрированного заявления нельзя архивировать.']);
        }

        $old = $this->audit->file($file);
        $file->update([
            'archived_by' => $actor?->id,
            'archived_at' => now(),
        ]);

        AuditLogService::log('Admissions', 'admission_document_file_archived', $file, $old, $this->audit->file($file), user: $actor);
    }

    /** @return array{mime:string,extension:string} */
    private function validateFile(UploadedFile $file): array
    {
        if ($file->getSize() <= 0) {
            throw ValidationException::withMessages(['file' => 'Пустой файл не допускается.']);
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Файл превышает допустимый размер 15 МБ.']);
        }

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => 'Недопустимое расширение файла.']);
        }

        $mime = (string) $file->getMimeType();
        if (! array_key_exists($mime, self::ALLOWED_MIME) || ! in_array($extension, self::ALLOWED_MIME[$mime], true)) {
            throw ValidationException::withMessages(['file' => 'MIME-тип файла не соответствует допустимым расширениям.']);
        }

        return ['mime' => $mime, 'extension' => $extension];
    }

    private function store(UploadedFile $file, string $extension, string $directory): string
    {
        $name = (string) Str::uuid().'.'.$extension;
        $path = trim($directory, '/').'/'.$name;

        $stored = Storage::disk('local')->putFileAs($directory, $file, $name);
        if ($stored === false) {
            throw ValidationException::withMessages(['file' => 'Не удалось сохранить файл документа в private storage.']);
        }

        return $path;
    }

    private function fileHash(UploadedFile $file): string
    {
        $realPath = $file->getRealPath();
        if (! $realPath || ! is_file($realPath)) {
            throw ValidationException::withMessages(['file' => 'Временный файл загрузки недоступен.']);
        }

        return hash_file('sha256', $realPath);
    }

    private function category(string $category): string
    {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw ValidationException::withMessages(['category' => 'Недопустимая категория файла документа.']);
        }

        return $category;
    }

    private function applicationId(mixed $applicationId, int $applicantId): ?int
    {
        if ($applicationId === null || $applicationId === '') {
            return null;
        }

        $application = AdmissionApplication::query()
            ->foundation()
            ->whereKey((int) $applicationId)
            ->where('applicant_id', $applicantId)
            ->first();

        if (! $application) {
            throw ValidationException::withMessages(['application_id' => 'Заявление не найдено или не относится к абитуриенту документа.']);
        }

        return $application->id;
    }

    private function assertActiveIdentity(IdentityDocument $document): void
    {
        if ($document->archived_at !== null) {
            throw ValidationException::withMessages(['document' => 'Нельзя загрузить файл к архивному документу.']);
        }
        if ($document->replaced_at !== null || $this->applicationDocuments->isIdentityLinkedToRegisteredApplication($document)) {
            throw ValidationException::withMessages(['document' => 'Файлы документа, закрепленного за зарегистрированным заявлением, недоступны для изменения.']);
        }
    }

    private function assertActiveEducation(EducationDocument $document): void
    {
        if ($document->archived_at !== null) {
            throw ValidationException::withMessages(['document' => 'Нельзя загрузить файл к архивному документу.']);
        }
        if ($document->replaced_at !== null || $this->applicationDocuments->isEducationLinkedToRegisteredApplication($document)) {
            throw ValidationException::withMessages(['document' => 'Файлы документа, закрепленного за зарегистрированным заявлением, недоступны для изменения.']);
        }
    }
}
