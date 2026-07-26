<?php

namespace App\Http\Controllers\Api\Admissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admissions\UploadAdmissionDocumentFileRequest;
use App\Http\Resources\Admissions\AdmissionDocumentFileResource;
use App\Models\Admissions\EducationDocument;
use App\Models\Admissions\IdentityDocument;
use App\Services\Admissions\AdmissionDocumentFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class AdmissionDocumentFileController extends Controller
{
    public function __construct(private readonly AdmissionDocumentFileService $files)
    {
    }

    public function uploadIdentity(UploadAdmissionDocumentFileRequest $request, IdentityDocument $document): JsonResponse
    {
        $file = $this->files->uploadIdentity($document, $request->file('file'), $request->validated(), $request->user());

        return (new AdmissionDocumentFileResource($file))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function uploadEducation(UploadAdmissionDocumentFileRequest $request, EducationDocument $document): JsonResponse
    {
        $file = $this->files->uploadEducation($document, $request->file('file'), $request->validated(), $request->user());

        return (new AdmissionDocumentFileResource($file))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function download(Request $request, int $file): mixed
    {
        $model = $this->files->findForDownload($file, $request->user());

        return Storage::disk('local')->download($model->storage_path, $model->original_name);
    }

    public function destroy(Request $request, int $file): Response
    {
        $this->files->archive($file, $request->user());

        return response()->noContent();
    }
}
