<?php

namespace App\Http\Controllers\Api\Admissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admissions\RegisterAdmissionApplicationRequest;
use App\Http\Requests\Admissions\StoreAdmissionApplicationRequest;
use App\Http\Requests\Admissions\UpdateAdmissionApplicationRequest;
use App\Http\Resources\Admissions\AdmissionApplicationResource;
use App\Services\Admissions\AdmissionApplicationService;
use App\Services\AuditLogService;
use App\Services\FisIntegration\FisApplicationReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AdmissionApplicationController extends Controller
{
    public function __construct(private readonly AdmissionApplicationService $applications)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'applicant_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:80'],
            'admission_year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'source_id' => ['nullable', 'integer'],
            'has_choices' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'with_archived' => ['nullable', 'boolean'],
        ]);

        AuditLogService::log('Admissions', 'admission_application_index', ['type' => 'AdmissionApplication', 'id' => null], null, [
            'filters' => array_intersect_key($filters, array_flip(['applicant_id', 'status', 'admission_year'])),
        ], user: $request->user());

        return AdmissionApplicationResource::collection($this->applications->paginate($filters));
    }

    /**
     * Чего не хватает заявлению для выгрузки в ФИС ГИА и Приёма.
     *
     * Это не действие, а взгляд на ту же карточку глазами схемы ФИС, поэтому и
     * право то же — просмотр заявления. Проверка ничего не пишет.
     *
     * До неё оператор узнавал о недостающем **при сборке пакета**, когда заявлений
     * уже сотни и непонятно, чьё чинить.
     */
    public function fisReadiness(int $application, FisApplicationReadinessService $readiness): JsonResponse
    {
        $model = $this->applications->find($application);
        abort_if(! $model, Response::HTTP_NOT_FOUND);

        return response()->json(['data' => $readiness->check($model)]);
    }

    public function show(Request $request, int $application): AdmissionApplicationResource
    {
        $model = $this->applications->find($application);
        abort_if(! $model, Response::HTTP_NOT_FOUND);

        AuditLogService::log('Admissions', 'admission_application_show', $model, null, ['id' => $model->id], user: $request->user());

        return new AdmissionApplicationResource($model);
    }

    public function store(StoreAdmissionApplicationRequest $request): JsonResponse
    {
        $application = $this->applications->createDraft($request->validated(), $request->user());

        return (new AdmissionApplicationResource($application))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdateAdmissionApplicationRequest $request, int $application): AdmissionApplicationResource
    {
        $model = $this->applications->find($application);
        abort_if(! $model, Response::HTTP_NOT_FOUND);

        return new AdmissionApplicationResource($this->applications->updateDraft($model, $request->validated(), $request->user()));
    }

    public function register(RegisterAdmissionApplicationRequest $request, int $application): AdmissionApplicationResource
    {
        $model = $this->applications->find($application);
        abort_if(! $model, Response::HTTP_NOT_FOUND);

        return new AdmissionApplicationResource($this->applications->register($model, $request->validated(), $request->user()));
    }
}
