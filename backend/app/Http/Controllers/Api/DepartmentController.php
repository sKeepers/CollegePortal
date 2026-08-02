<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return DepartmentResource::collection(Department::with('parent')->orderBy('name')->paginate($request->integer('per_page') ?: 100));
    }

    public function store(Request $request): DepartmentResource
    {
        abort_unless($request->user()->hasPermission('hr.departments.manage'), 403);
        $department = Department::create($this->data($request));
        AuditLogService::log('hr', 'department_created', $department, null, $department->toArray(), $request);

        return new DepartmentResource($department);
    }

    public function update(Request $request, Department $department): DepartmentResource
    {
        abort_unless($request->user()->hasPermission('hr.departments.manage'), 403);
        $old = $department->toArray();
        $department->update($this->data($request, $department));
        AuditLogService::log('hr', 'department_updated', $department, $old, $department->toArray(), $request);

        return new DepartmentResource($department);
    }

    public function destroy(Request $request, Department $department): Response
    {
        abort_unless($request->user()->hasPermission('hr.departments.manage'), 403);
        $department->delete();

        return response()->noContent();
    }

    private function data(Request $request, ?Department $department = null): array
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:100', Rule::unique('departments', 'code')->ignore($department?->id)],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:departments,id'],
            'type' => ['nullable', 'string', 'max:100'],
            'head_employee_id' => ['nullable', 'exists:employees,id'],
            'is_active' => ['boolean'],
        ]);
        $data['code'] = $data['code'] ?: $department?->code ?: $this->generatedCode($data['name']);

        return $data;
    }

    private function generatedCode(string $name): string
    {
        $base = Str::upper(Str::slug($name)) ?: 'DEPARTMENT';
        $code = $base;
        $suffix = 2;

        while (Department::where('code', $code)->exists()) {
            $code = "{$base}-{$suffix}";
            $suffix++;
        }

        return $code;
    }
}
