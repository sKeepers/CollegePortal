<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\IssueStudentCertificateRequest;
use App\Http\Resources\StudentCertificateResource;
use App\Models\Student;
use App\Models\StudentCertificate;
use App\Services\Students\StudentCertificateService;
use App\Support\Http\PageSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Справки студентам и реестр выданных.
 *
 * Реестр читают шире, чем выдают: справку выписывает учебная часть, а отвечает
 * на вопрос «выдавали ли такую» кто угодно из тех, кто ведёт студентов.
 * Поэтому `certificates.view` отдельно от `certificates.manage`.
 */
class StudentCertificateController extends Controller
{
    public function __construct(private readonly StudentCertificateService $certificates)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StudentCertificate::query()
            ->with(['student.group', 'issuedBy'])
            ->when($request->integer('student_id'), fn ($q, int $id) => $q->where('student_id', $id))
            ->when($request->integer('group_id'), fn ($q, int $id) => $q->whereHas('student', fn ($s) => $s->where('group_id', $id)))
            ->when($request->string('number')->toString(), fn ($q, string $number) => $q->where('number', $number))
            ->orderByDesc('number');

        return StudentCertificateResource::collection($query->paginate(PageSize::from($request, 50)));
    }

    /** Реестр: строки за год, в порядке номеров — как на бумаге. */
    public function registry(Request $request): JsonResponse
    {
        $year = $request->integer('year') ?: null;
        $groupId = $request->integer('group_id') ?: null;

        $rows = $this->certificates->registry($year, $groupId);

        return response()->json([
            'data' => StudentCertificateResource::collection($rows)->resolve(),
            'meta' => [
                'total' => $rows->count(),
                'year' => $year,
                'group_id' => $groupId,
                'years' => $this->certificates->years(),
            ],
        ]);
    }

    public function store(IssueStudentCertificateRequest $request, Student $student): JsonResponse
    {
        $issued = $this->certificates->issue(
            $student,
            (int) ($request->validated('copies') ?? StudentCertificateService::COPIES),
            $request->user()?->id,
            $request->validated('issued_on'),
        );

        return response()->json([
            'data' => StudentCertificateResource::collection($issued)->resolve(),
        ], Response::HTTP_CREATED);
    }

    /** Отметка о получении — та самая графа реестра, что заполняется от руки. */
    public function received(Request $request, StudentCertificate $studentCertificate): StudentCertificateResource
    {
        $data = $request->validate([
            'received_on' => ['nullable', 'date'],
        ]);

        return new StudentCertificateResource(
            $this->certificates->markReceived($studentCertificate, $data['received_on'] ?? null),
        );
    }
}
