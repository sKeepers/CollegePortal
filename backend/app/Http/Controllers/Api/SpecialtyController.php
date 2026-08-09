<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSpecialtyRequest;
use App\Http\Requests\UpdateSpecialtyRequest;
use App\Http\Resources\SpecialtyResource;
use App\Models\Specialty;
use App\Services\SpecialtyCsvService;
use App\Services\AutoCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpecialtyController extends Controller
{
    public function __construct(
        private readonly SpecialtyCsvService $specialtyCsvService,
        private readonly AutoCodeService $autoCodeService,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $specialties = Specialty::query()
            ->when($request->string('search')->toString(), function ($query, string $search): void {
                $search = mb_strtolower($search);

                $query->where(function ($query) use ($search): void {
                    $query
                        ->whereRaw('lower(code) like ?', ["%{$search}%"])
                        ->orWhereRaw('lower(name) like ?', ["%{$search}%"])
                        ->orWhereRaw('lower(qualification) like ?', ["%{$search}%"]);
                });
            })
            ->orderBy('code')
            ->paginate(50);

        return SpecialtyResource::collection($specialties);
    }

    public function store(StoreSpecialtyRequest $request): JsonResponse
    {
        $data = $request->validated();
        // Код необязателен, поэтому ключа может не быть вовсе: клиент, который
        // просто не прислал поле, не должен получать пятисотую.
        $data['code'] = ($data['code'] ?? null) ?: $this->autoCodeService->specialtyCode($data['name'] ?? null);
        $specialty = Specialty::create($data);

        return (new SpecialtyResource($specialty))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Specialty $specialty): SpecialtyResource
    {
        return new SpecialtyResource($specialty);
    }

    public function update(UpdateSpecialtyRequest $request, Specialty $specialty): SpecialtyResource
    {
        $data = $request->validated();
        if (array_key_exists('code', $data) && ! $data['code']) {
            $data['code'] = $this->autoCodeService->specialtyCode($data['name'] ?? $specialty->name);
        }
        $specialty->update($data);

        return new SpecialtyResource($specialty);
    }

    public function destroy(Specialty $specialty): Response
    {
        $specialty->delete();

        return response()->noContent();
    }

    public function export(): StreamedResponse
    {
        return $this->specialtyCsvService->export();
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $summary = $this->specialtyCsvService->import($request->file('file'));

        return response()->json(['data' => $summary]);
    }
}
