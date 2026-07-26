<?php

namespace App\Http\Controllers\Api\Admissions;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admissions\UpdateApplicantSnilsRequest;
use App\Models\Admissions\Applicant;
use App\Services\Admissions\DocumentMaskingService;
use App\Services\Admissions\SnilsService;
use Illuminate\Http\JsonResponse;

class ApplicantSnilsController extends Controller
{
    public function __construct(
        private readonly SnilsService $snils,
        private readonly DocumentMaskingService $masking,
    ) {
    }

    public function update(UpdateApplicantSnilsRequest $request, int $applicant): JsonResponse
    {
        $model = Applicant::query()->with('person')->active()->find($applicant);
        abort_if(! $model || ! $model->person, 404);

        $person = $this->snils->update($model->person, $request->validated()['snils'] ?? null, $request->user());

        return response()->json([
            'data' => [
                'applicant_id' => $model->id,
                'person_id' => $person->id,
                'has_snils' => filled($person->snils),
                'snils_masked' => $this->masking->snils($person->snils),
            ],
        ]);
    }
}
