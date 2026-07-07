<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImportJobResource;
use App\Models\ImportJob;
use App\Services\UniversalImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use RuntimeException;

class UniversalImportController extends Controller
{
    public function __construct(private readonly UniversalImportService $importService)
    {
    }

    public function config(): array
    {
        return ['data' => $this->importService->config()];
    }

    public function history(Request $request): AnonymousResourceCollection
    {
        $jobs = ImportJob::query()
            ->with('user')
            ->when($request->string('data_type')->toString(), fn ($query, string $type) => $query->where('data_type', $type))
            ->latest()
            ->paginate(20);

        return ImportJobResource::collection($jobs);
    }

    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'data_type' => ['required', 'string'],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ]);

        try {
            $job = $this->importService->createPreview($request->file('file'), $request->string('data_type')->toString(), $request->user());
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return (new ImportJobResource($job->load('user')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function validateJob(Request $request, ImportJob $importJob): ImportJobResource|JsonResponse
    {
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mode' => ['required', 'string'],
        ]);

        try {
            $job = $this->importService->validateJob($importJob, $data['mapping'], $data['mode']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new ImportJobResource($job->load('user'));
    }

    public function confirm(Request $request, ImportJob $importJob): ImportJobResource|JsonResponse
    {
        $data = $request->validate([
            'mapping' => ['required', 'array'],
            'mode' => ['required', 'string'],
        ]);

        try {
            $job = $this->importService->confirmJob($importJob, $data['mapping'], $data['mode']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new ImportJobResource($job->load('user'));
    }
}
