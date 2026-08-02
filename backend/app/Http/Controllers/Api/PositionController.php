<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PositionResource;
use App\Models\Position;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PositionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return PositionResource::collection(Position::orderBy('name')->paginate($request->integer('per_page') ?: 100));
    }

    public function store(Request $request): PositionResource
    {
        abort_unless($request->user()->hasPermission('hr.positions.manage'), 403);
        $position = Position::create($this->data($request));
        AuditLogService::log('hr', 'position_created', $position, null, $position->toArray(), $request);

        return new PositionResource($position);
    }

    public function update(Request $request, Position $position): PositionResource
    {
        abort_unless($request->user()->hasPermission('hr.positions.manage'), 403);
        $old = $position->toArray();
        $position->update($this->data($request, $position));
        AuditLogService::log('hr', 'position_updated', $position, $old, $position->toArray(), $request);

        return new PositionResource($position);
    }

    public function destroy(Request $request, Position $position): Response
    {
        abort_unless($request->user()->hasPermission('hr.positions.manage'), 403);
        $position->delete();

        return response()->noContent();
    }

    private function data(Request $request, ?Position $position = null): array
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:100', Rule::unique('positions', 'code')->ignore($position?->id)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'is_teaching_position' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
        $data['code'] = ($data['code'] ?? null) ?: $position?->code ?: $this->generatedCode($data['name']);

        return $data;
    }

    private function generatedCode(string $name): string
    {
        $base = Str::upper(Str::slug($name)) ?: 'POSITION';
        $code = $base;
        $suffix = 2;

        while (Position::where('code', $code)->exists()) {
            $code = "{$base}-{$suffix}";
            $suffix++;
        }

        return $code;
    }
}
