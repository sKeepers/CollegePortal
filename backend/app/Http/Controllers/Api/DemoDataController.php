<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DemoDataController extends Controller
{
    public function status(DemoDataSeeder $demo): array
    {
        return ['data' => $demo->summary()];
    }

    public function create(DemoDataSeeder $demo): JsonResponse
    {
        $summary = $demo->seedDemo();
        AuditLogService::log('demo_data', 'create_demo', ['type' => 'demo_data', 'id' => null], null, $summary, request());

        return response()->json([
            'message' => 'Демонстрационная база создана или обновлена.',
            'data' => $summary,
        ]);
    }

    public function clear(DemoDataSeeder $demo): JsonResponse
    {
        $result = $demo->resetDemo();
        AuditLogService::log('demo_data', 'clear_demo', ['type' => 'demo_data', 'id' => null], null, $result, request());

        return response()->json([
            'message' => 'Демонстрационные данные очищены.',
            'data' => $result,
        ]);
    }

    public function export(DemoDataSeeder $demo): StreamedResponse
    {
        $filename = 'demo-data-summary-'.now()->format('Ymd-His').'.csv';
        AuditLogService::log('demo_data', 'export', ['type' => 'demo_data', 'id' => null], null, ['filename' => $filename], request());

        return response()->streamDownload(function () use ($demo): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['entity', 'count'], ';');
            foreach ($demo->summary() as $entity => $count) {
                fputcsv($output, [$entity, is_bool($count) ? ($count ? '1' : '0') : $count], ';');
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $data = [
            'filename' => $request->file('file')->getClientOriginalName(),
            'size' => $request->file('file')->getSize(),
        ];
        AuditLogService::log('demo_data', 'import', ['type' => 'demo_data', 'id' => null], null, $data, $request);

        return response()->json([
            'message' => 'Файл принят. Расширенный импорт демо-данных будет подключен после согласования формата.',
            'data' => $data,
        ]);
    }
}
