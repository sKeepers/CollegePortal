<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class AdminSettingController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => SettingService::groupedPayload(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        // Признак нужен экрану, чтобы предложить подтверждение, а не разбирать
        // текст сообщения. Без него запрет выглядел как ошибка без выхода:
        // подтвердить было нечем, и настройки на production не сохранялись вовсе.
        if (app()->environment('production') && ! $request->boolean('confirm_production')) {
            return response()->json([
                'message' => 'Изменение production-настроек требует отдельного подтверждения.',
                'requires_production_confirmation' => true,
            ], Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'reset_to_defaults' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'settings.*.group' => ['required_with:settings', 'string', 'max:100'],
            'settings.*.key' => ['required_with:settings', 'string', 'max:100'],
            'settings.*.value' => ['nullable'],
            'confirm_production' => ['sometimes', 'boolean'],
        ]);

        $old = SettingService::groupedPayload();

        try {
            if ($request->boolean('reset_to_defaults')) {
                SettingService::resetToDefaults();
                AuditLogService::log('settings', 'reset_defaults', ['type' => 'settings', 'id' => null], $old, SettingService::groupedPayload(), $request);
            } else {
                SettingService::updateMany($data['settings'] ?? []);
                AuditLogService::log('settings', 'update', ['type' => 'settings', 'id' => null], $old, SettingService::groupedPayload(), $request);
            }
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => $request->boolean('reset_to_defaults') ? 'Настройки сброшены к значениям по умолчанию.' : 'Настройки сохранены.',
            'data' => SettingService::groupedPayload(),
        ]);
    }

    public function publicSettings(): JsonResponse
    {
        return response()->json([
            'data' => SettingService::publicSettings(),
        ]);
    }
}
