<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DashboardLayoutResource;
use App\Models\DashboardLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class DashboardLayoutController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'dashboard_type' => ['nullable', 'string', 'max:80'],
        ]);

        $query = DashboardLayout::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('is_default')
            ->orderBy('name');

        if (! empty($data['dashboard_type'])) {
            $query->where('dashboard_type', $data['dashboard_type']);
        }

        return DashboardLayoutResource::collection($query->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPayload($request);
        $data['user_id'] = $request->user()->id;

        if (! empty($data['is_default'])) {
            $this->clearDefault($request->user()->id, $data['dashboard_type']);
        }

        $layout = DashboardLayout::query()->create($data);

        return response()->json([
            'message' => 'Профиль Dashboard сохранен.',
            'data' => new DashboardLayoutResource($layout),
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, DashboardLayout $dashboardLayout): JsonResponse
    {
        $this->authorizeOwner($request, $dashboardLayout);
        $data = $this->validatedPayload($request, $dashboardLayout->id);

        if (! empty($data['is_default'])) {
            $this->clearDefault($request->user()->id, $data['dashboard_type']);
        }

        $dashboardLayout->update($data);

        return response()->json([
            'message' => 'Профиль Dashboard обновлен.',
            'data' => new DashboardLayoutResource($dashboardLayout->refresh()),
        ]);
    }

    public function destroy(Request $request, DashboardLayout $dashboardLayout): JsonResponse
    {
        $this->authorizeOwner($request, $dashboardLayout);
        $dashboardLayout->delete();

        return response()->json(['message' => 'Профиль Dashboard удален.']);
    }

    public function activate(Request $request, DashboardLayout $dashboardLayout): JsonResponse
    {
        $this->authorizeOwner($request, $dashboardLayout);
        $this->clearDefault($request->user()->id, $dashboardLayout->dashboard_type);
        $dashboardLayout->forceFill(['is_default' => true])->save();

        return response()->json([
            'message' => 'Профиль Dashboard активирован.',
            'data' => new DashboardLayoutResource($dashboardLayout->refresh()),
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dashboard_type' => ['required', 'string', 'max:80'],
        ]);

        DashboardLayout::query()
            ->where('user_id', $request->user()->id)
            ->where('dashboard_type', $data['dashboard_type'])
            ->delete();

        return response()->json([
            'message' => 'Dashboard возвращен к стандартному виду.',
            'data' => [],
        ]);
    }

    private function validatedPayload(Request $request, ?int $ignoreId = null): array
    {
        $userId = $request->user()->id;

        return $request->validate([
            'dashboard_type' => ['required', 'string', 'max:80'],
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('dashboard_layouts', 'name')
                    ->where(fn ($query) => $query
                        ->where('user_id', $userId)
                        ->where('dashboard_type', $request->input('dashboard_type')))
                    ->ignore($ignoreId),
            ],
            'layout' => ['required', 'array'],
            'layout.widgets' => ['required', 'array'],
            'layout.widgets.*.id' => ['required', 'string', 'max:120'],
            'layout.widgets.*.order' => ['required', 'integer', 'min:0'],
            'layout.widgets.*.size' => ['required', 'string', 'max:20'],
            'layout.widgets.*.visible' => ['required', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    private function authorizeOwner(Request $request, DashboardLayout $dashboardLayout): void
    {
        abort_if((int) $dashboardLayout->user_id !== (int) $request->user()->id, Response::HTTP_FORBIDDEN, 'Можно изменять только собственные профили Dashboard.');
    }

    private function clearDefault(int $userId, string $dashboardType): void
    {
        DashboardLayout::query()
            ->where('user_id', $userId)
            ->where('dashboard_type', $dashboardType)
            ->update(['is_default' => false]);
    }
}
