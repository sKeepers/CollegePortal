<?php

namespace App\Http\Controllers\Api\Admissions;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admissions\ApplicantResource;
use App\Services\Admissions\ApplicantService;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only API foundation-профилей абитуриентов.
 */
class ApplicantController extends Controller
{
    public function __construct(private readonly ApplicantService $applicants)
    {
    }

    /**
     * Возвращает список foundation-профилей абитуриентов.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        if ($request->has('with_archived')) {
            $withArchived = filter_var($request->query('with_archived'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($withArchived !== null) {
                $request->merge(['with_archived' => $withArchived]);
            }
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'max:100'],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'with_archived' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $applicants = $this->applicants->paginate($filters);

        AuditLogService::log('Admissions', 'applicant_index', ['type' => 'Applicant', 'id' => null], null, [
            'filters' => array_intersect_key($filters, array_flip(['status', 'source', 'responsible_user_id', 'with_archived'])),
            'count' => $applicants->count(),
        ], $request);

        return ApplicantResource::collection($applicants);
    }

    /**
     * Возвращает карточку foundation-профиля абитуриента.
     */
    public function show(Request $request, int $id): ApplicantResource
    {
        $applicant = $this->applicants->find($id);

        abort_if($applicant === null, 404, 'Абитуриент не найден.');

        AuditLogService::log('Admissions', 'applicant_show', $applicant, null, [
            'person_id' => $applicant->person_id,
        ], $request);

        return new ApplicantResource($applicant);
    }
}
