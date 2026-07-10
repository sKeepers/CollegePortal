<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AttendanceAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceAnalysisController extends Controller
{
    public function teachersToday(Request $request, AttendanceAnalysisService $service): JsonResponse
    {
        return response()->json($service->teachersToday(filters: $this->filters($request)));
    }

    public function studentsToday(Request $request, AttendanceAnalysisService $service): JsonResponse
    {
        return response()->json($service->studentsToday(filters: $this->filters($request)));
    }


    public function history(Request $request, AttendanceAnalysisService $service): JsonResponse|StreamedResponse
    {
        $filters = $this->filters($request) + $request->validate([
            'type' => ['nullable', 'string', 'in:student,teacher'],
            'person_id' => ['nullable', 'integer'],
            'export' => ['nullable', 'string', 'in:csv'],
        ]);

        if (($filters['export'] ?? null) === 'csv') {
            $rows = $service->historyCsvRows($filters);

            return response()->streamDownload(function () use ($rows): void {
                $handle = fopen('php://output', 'w');
                fputs($handle, "\xEF\xBB\xBF");
                if ($rows === []) {
                    fputcsv($handle, ['ФИО', 'Тип', 'Дней по расписанию', 'Присутствовал', 'Отсутствовал', 'Опозданий', 'Минут опоздания', 'Ранних уходов', 'Минут раннего ухода', 'Время внутри, минут', 'Среднее в день, минут', 'Незакрытая сессия'], ';');
                } else {
                    fputcsv($handle, array_keys($rows[0]), ';');
                    foreach ($rows as $row) {
                        fputcsv($handle, $row, ';');
                    }
                }
                fclose($handle);
            }, 'attendance-history.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->json($service->history($filters));
    }

    public function personSummary(Request $request, string $type, int $id, AttendanceAnalysisService $service): JsonResponse
    {
        return response()->json($service->personSummary($type, $id, $this->filters($request)));
    }

    public function personDays(Request $request, string $type, int $id, AttendanceAnalysisService $service): JsonResponse
    {
        return response()->json($service->personDays($type, $id, $this->filters($request)));
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', 'string', 'max:64'],
            'group_id' => ['nullable', 'integer'],
            'teacher_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);
    }
}
