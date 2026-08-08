<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $permissions = $this->resolvePermissions($request, $permission);

        foreach ($permissions as $candidate) {
            if (Gate::allows('permission', $candidate)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'У вас нет доступа к этому действию.'], Response::HTTP_FORBIDDEN);
    }

    /** @return list<string> */
    private function resolvePermissions(Request $request, string $permission): array
    {
        $path = trim($request->path(), '/');
        $method = $request->method();
        $mapped = match ($permission) {
            'manage_users' => $this->systemPermission($path, $method),
            'manage_dictionaries' => $this->domainPermission($path, $method),
            'manage_schedule' => $this->schedulePermission($method),
            'manage_journal' => $this->journalPermission($path, $method),
            'view_reports' => $this->reportPermission($path),
            default => $permission,
        };

        if (str_starts_with($path, 'api/digital-identities') && in_array($method, ['GET', 'HEAD'], true)) {
            return array_values(array_unique(array_filter(['digitalpasses.manage', 'view_own_data', $permission])));
        }

        if ($path === 'api/teaching-loads' && in_array($method, ['GET', 'HEAD'], true)) {
            return array_values(array_unique(array_filter(['teachingload.view', 'view_own_data', $permission])));
        }

        return array_values(array_unique(array_filter([$mapped, $permission])));
    }

    private function systemPermission(string $path, string $method): string
    {
        if (str_starts_with($path, 'api/admin/audit')) {
            return 'audit.view';
        }
        if (str_starts_with($path, 'api/admin/settings')) {
            return $method === 'GET' ? 'settings.manage' : 'settings.manage';
        }
        if (str_starts_with($path, 'api/admin/permissions')) {
            return 'permissions.manage';
        }
        if (str_starts_with($path, 'api/admin/roles')) {
            return 'roles.manage';
        }
        if (str_starts_with($path, 'api/admin/users/roles') || str_starts_with($path, 'api/admin/users/people') || str_starts_with($path, 'api/admin/users')) {
            return 'users.manage';
        }

        return 'users.manage';
    }

    private function reportPermission(string $path): string
    {
        if (str_starts_with($path, 'api/attendance')) {
            return 'attendance.reports';
        }
        if (str_starts_with($path, 'api/dashboard/analytics')) {
            return 'dashboard.view';
        }

        return 'attendance.reports';
    }

    private function schedulePermission(string $method): string
    {
        return in_array($method, ['GET', 'HEAD'], true) ? 'schedule.view' : 'schedule.update';
    }

    private function journalPermission(string $path, string $method): string
    {
        if (str_contains($path, '/export')) {
            return 'journal.export';
        }
        if (str_starts_with($path, 'api/reports/')) {
            return in_array($method, ['GET', 'HEAD'], true) ? 'journal.view' : 'journal.export';
        }

        return in_array($method, ['GET', 'HEAD'], true) ? 'journal.view' : 'journal.edit';
    }

    private function domainPermission(string $path, string $method): string
    {
        $rules = [
            'api/admin/reference' => ['reference.manage', 'reference.manage', 'reference.manage', 'reference.manage'],
            'api/admin/import' => ['import.manage', 'import.manage', 'import.manage', 'import.manage'],
            'api/admin/demo-data' => ['import.manage', 'import.manage', 'import.manage', 'import.manage'],
            'api/people' => ['people.view', 'people.create', 'people.update', 'people.merge'],
            'api/person-photos/student' => ['students.view', 'students.update', 'students.update', 'students.update'],
            'api/person-photos/teacher' => ['teachers.view', 'teachers.update', 'teachers.update', 'teachers.update'],
            'api/person-photos/alumni' => ['graduation.view', 'graduation.edit', 'graduation.edit', 'graduation.edit'],
            'api/access/scan' => ['gate.scan', 'gate.scan', 'gate.scan', 'gate.scan'],
            'api/access/events' => ['gate.reports', 'gate.reports', 'gate.reports', 'gate.reports'],
            'api/access/reports' => ['gate.reports', 'gate.reports', 'gate.reports', 'gate.reports'],
            // Список эвакуации читают все, кто и так видит отчеты проходной;
            // справочник корпусов и точек правит только тот, кто им владеет.
            'api/access/muster' => ['gate.reports', 'gate.reports', 'gate.reports', 'gate.reports'],
            'api/access/buildings' => ['gate.reports', 'gate.points.manage', 'gate.points.manage', 'gate.points.manage'],
            'api/access/points' => ['gate.reports', 'gate.points.manage', 'gate.points.manage', 'gate.points.manage'],
            'api/digital-identities' => ['digitalpasses.manage', 'digitalpasses.manage', 'digitalpasses.manage', 'digitalpasses.manage'],
            'api/applicant-applications' => ['admissions.view', 'admissions.edit', 'admissions.edit', 'admissions.edit'],
            'api/curriculum-items' => ['curricula.view', 'curricula.edit', 'curricula.edit', 'curricula.edit'],
            'api/curricula' => ['curricula.view', 'curricula.edit', 'curricula.edit', 'curricula.edit'],
            'api/education-programs' => ['reference.manage', 'reference.manage', 'reference.manage', 'reference.manage'],
            'api/exam-results' => ['exams.view', 'exams.edit', 'exams.edit', 'exams.edit'],
            'api/exams' => ['exams.view', 'exams.edit', 'exams.edit', 'exams.edit'],
            'api/frdo-packages' => ['frdo.view', 'frdo.export', 'frdo.export', 'frdo.export'],
            'api/fis/outbound' => ['fis.outbound.view', 'fis.outbound.create', 'fis.outbound.generate', 'fis.outbound.generate'],
            'api/fis-packages' => ['fis.view', 'fis.export', 'fis.export', 'fis.export'],
            'api/groups' => ['groups.view', 'groups.create', 'groups.update', 'groups.delete'],
            'api/graduates' => ['graduation.view', 'graduation.edit', 'graduation.edit', 'graduation.edit'],
            'api/specialties' => ['reference.manage', 'reference.manage', 'reference.manage', 'reference.manage'],
            'api/students' => ['students.view', 'students.create', 'students.update', 'students.delete'],
            'api/subjects' => ['subjects.view', 'subjects.create', 'subjects.update', 'subjects.delete'],
            'api/teaching-load-items' => ['teachingload.view', 'teachingload.edit', 'teachingload.edit', 'teachingload.edit'],
            'api/teaching-loads' => ['teachingload.view', 'teachingload.edit', 'teachingload.edit', 'teachingload.edit'],
            'api/teachers' => ['teachers.view', 'teachers.create', 'teachers.update', 'teachers.delete'],
            'api/classrooms' => ['classrooms.view', 'classrooms.create', 'classrooms.update', 'classrooms.delete'],
        ];

        foreach ($rules as $prefix => $permissions) {
            if (str_starts_with($path, $prefix)) {
                return $this->methodPermission($method, $path, $permissions);
            }
        }

        return 'reference.manage';
    }

    /** @param array{0:string,1:string,2:string,3:string} $permissions */
    private function methodPermission(string $method, string $path, array $permissions): string
    {
        if ($method === 'GET' || $method === 'HEAD') {
            if (str_contains($path, '/export') || str_contains($path, 'export.')) {
                return $permissions[2];
            }
            return $permissions[0];
        }

        if ($method === 'POST') {
            if (str_contains($path, '/import') || str_contains($path, '/validate') || str_contains($path, '/mark-exported') || str_contains($path, '/archive') || str_contains($path, '/enroll') || str_contains($path, '/items') || str_contains($path, '/results') || str_contains($path, '/diploma') || str_contains($path, '/supplement')) {
                return $permissions[2];
            }
            return $permissions[1];
        }

        if (in_array($method, ['PUT', 'PATCH'], true)) {
            return $permissions[2];
        }

        if ($method === 'DELETE') {
            return $permissions[3];
        }

        return $permissions[0];
    }
}
