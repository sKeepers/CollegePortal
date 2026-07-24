<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UatFeedbackResource;
use App\Http\Resources\UatTestRunResource;
use App\Models\UatFeedbackComment;
use App\Models\UatFeedback;
use App\Models\UatTestResult;
use App\Models\UatTestRun;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\UatScenarioService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UatController extends Controller
{
    public function __construct(private readonly UatScenarioService $scenarioService) {}

    public function config(): JsonResponse
    {
        $accounts = collect($this->scenarioService->uatAccounts())->map(function (array $account): array {
            $user = User::query()->with('role')->where('email', $account['email'])->first();
            return $account + [
                'exists' => (bool) $user,
                'user_id' => $user?->id,
                'name' => $user?->name,
                'role_name' => $user?->role?->name,
            ];
        });

        return response()->json([
            'roles' => array_keys($this->scenarioService->scenarios()),
            'scenarios' => $this->scenarioService->scenarios(),
            'accounts' => $accounts,
        ]);
    }

    public function runs(Request $request): AnonymousResourceCollection
    {
        $query = UatTestRun::query()
            ->with(['tester.role', 'tester.roles', 'creator.role', 'creator.roles', 'results'])
            ->when($request->string('role_code')->toString(), fn (Builder $q, string $role) => $q->where('role_code', $role))
            ->when($request->string('status')->toString(), fn (Builder $q, string $status) => $q->where('status', $status))
            ->latest();

        return UatTestRunResource::collection($query->paginate($request->integer('per_page') ?: 50));
    }

    public function storeRun(Request $request): UatTestRunResource
    {
        $roles = array_keys($this->scenarioService->scenarios());
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'role_code' => ['required', Rule::in($roles)],
            'tester_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'summary' => ['nullable', 'string', 'max:5000'],
        ]);

        $run = UatTestRun::create([
            ...$data,
            'status' => UatTestRun::STATUS_IN_PROGRESS,
            'started_at' => now(),
            'created_by' => $request->user()->id,
        ]);

        foreach ($this->scenarioService->roleScenarios($run->role_code) as $scenario) {
            UatTestResult::create([
                'test_run_id' => $run->id,
                'scenario_code' => $scenario['code'],
                'status' => 'not_started',
            ]);
        }

        AuditLogService::log('uat', 'test_run_created', $run, null, $run->toArray(), $request);

        return new UatTestRunResource($run->load(['tester.role', 'tester.roles', 'creator.role', 'creator.roles', 'results']));
    }

    public function showRun(UatTestRun $run): UatTestRunResource
    {
        return new UatTestRunResource($run->load(['tester.role', 'tester.roles', 'creator.role', 'creator.roles', 'results']));
    }

    public function updateResult(Request $request, UatTestRun $run, UatTestResult $result): UatTestRunResource
    {
        abort_unless((int) $result->test_run_id === (int) $run->id, 404);
        $data = $request->validate([
            'status' => ['required', Rule::in(UatTestResult::STATUSES)],
            'comment' => ['nullable', 'string', 'max:5000'],
            'actual_result' => ['nullable', 'string', 'max:5000'],
            'screenshot' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
        ]);

        $old = $result->toArray();
        if ($request->hasFile('screenshot')) {
            if ($result->screenshot_path) {
                Storage::disk('local')->delete($result->screenshot_path);
            }
            $data['screenshot_path'] = $request->file('screenshot')->store('private/uat/results', 'local');
        }
        unset($data['screenshot']);
        $result->update($data);
        AuditLogService::log('uat', 'test_result_updated', $result, $old, $result->toArray(), $request);

        return new UatTestRunResource($run->fresh()->load(['tester.role', 'tester.roles', 'creator.role', 'creator.roles', 'results']));
    }

    public function completeRun(Request $request, UatTestRun $run): UatTestRunResource
    {
        $data = $request->validate(['summary' => ['nullable', 'string', 'max:5000']]);
        $run->update(['status' => UatTestRun::STATUS_COMPLETED, 'completed_at' => now(), 'summary' => $data['summary'] ?? $run->summary]);
        AuditLogService::log('uat', 'test_run_completed', $run, null, $run->toArray(), $request);

        return new UatTestRunResource($run->load(['tester.role', 'tester.roles', 'creator.role', 'creator.roles', 'results']));
    }

    public function feedback(Request $request): AnonymousResourceCollection
    {
        $query = UatFeedback::query()
            ->with(['user.role', 'user.roles', 'assignee.role', 'assignee.roles'])
            ->when($request->string('status')->toString(), fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($request->string('role_code')->toString(), fn (Builder $q, string $role) => $q->where('role_code', $role))
            ->when($request->string('category')->toString(), fn (Builder $q, string $category) => $q->where('category', $category))
            ->when($request->string('severity')->toString(), fn (Builder $q, string $severity) => $q->where('severity', $severity))
            ->when($request->integer('author_id'), fn (Builder $q, int $authorId) => $q->where('user_id', $authorId))
            ->when($request->string('page')->toString(), fn (Builder $q, string $page) => $q->where('page_url', 'like', "%{$page}%"))
            ->when($request->string('version')->toString(), fn (Builder $q, string $version) => $q->where('app_version', $version))
            ->when($request->date('date_from'), fn (Builder $q, mixed $date) => $q->whereDate('created_at', '>=', $date))
            ->when($request->date('date_to'), fn (Builder $q, mixed $date) => $q->whereDate('created_at', '<=', $date))
            ->when($request->string('q')->toString(), function (Builder $q, string $search): void {
                $q->where(function (Builder $nested) use ($search): void {
                    $nested->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('expected_result', 'like', "%{$search}%")
                        ->orWhere('actual_result', 'like', "%{$search}%")
                        ->orWhere('page_url', 'like', "%{$search}%")
                        ->orWhere('app_version', 'like', "%{$search}%")
                        ->orWhere('build_hash', 'like', "%{$search}%");
                });
            })
            ->latest();

        return UatFeedbackResource::collection($query->paginate($request->integer('per_page') ?: 50));
    }

    public function showFeedback(UatFeedback $feedback): UatFeedbackResource
    {
        return new UatFeedbackResource($feedback->load([
            'user.role',
            'user.roles',
            'assignee.role',
            'assignee.roles',
            'statusHistory.user.role',
            'statusHistory.user.roles',
            'comments.user.role',
            'comments.user.roles',
        ]));
    }

    public function storeFeedback(Request $request): UatFeedbackResource
    {
        $data = $request->validate([
            'role_code' => ['nullable', 'string', 'max:50'],
            'category' => ['required', Rule::in(UatFeedback::CATEGORIES)],
            'severity' => ['required', Rule::in(UatFeedback::SEVERITIES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'expected_result' => ['nullable', 'string', 'max:5000'],
            'actual_result' => ['nullable', 'string', 'max:5000'],
            'page_url' => ['nullable', 'string', 'max:2000'],
            'app_version' => ['nullable', 'string', 'max:100'],
            'build_hash' => ['nullable', 'string', 'max:100'],
            'environment' => ['nullable', 'string', 'max:100'],
            'browser' => ['nullable', 'string', 'max:255'],
            'screenshot' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp'],
        ]);

        if ($request->hasFile('screenshot')) {
            $data['screenshot_path'] = $request->file('screenshot')->store('private/uat/feedback', 'local');
        }
        unset($data['screenshot']);

        $feedback = UatFeedback::create([
            ...$data,
            'user_id' => $request->user()?->id,
            'user_agent' => $request->userAgent(),
            'status' => 'new',
        ]);
        $feedback->statusHistory()->create([
            'user_id' => $request->user()?->id,
            'old_status' => null,
            'new_status' => 'new',
            'comment' => null,
        ]);
        AuditLogService::log('uat', 'feedback_created', $feedback, null, $feedback->toArray(), $request);

        return new UatFeedbackResource($feedback->load(['user.role', 'user.roles', 'assignee.role', 'assignee.roles', 'statusHistory.user', 'comments.user']));
    }

    public function updateFeedback(Request $request, UatFeedback $feedback): UatFeedbackResource
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(UatFeedback::STATUSES)],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'resolution' => ['nullable', 'string', 'max:5000'],
            'status_comment' => ['nullable', 'string', 'max:2000'],
            'github_issue_number' => ['nullable', 'integer', 'min:1'],
            'github_issue_url' => ['nullable', 'url', 'max:255'],
            'github_issue_status' => ['nullable', 'string', 'max:50'],
        ]);
        $old = $feedback->toArray();
        $oldStatus = $feedback->status;
        $statusComment = $data['status_comment'] ?? null;
        unset($data['status_comment']);
        $feedback->update($data);
        if (array_key_exists('status', $data) && $data['status'] !== $oldStatus) {
            $feedback->statusHistory()->create([
                'user_id' => $request->user()?->id,
                'old_status' => $oldStatus,
                'new_status' => $data['status'],
                'comment' => $statusComment,
            ]);
        }
        AuditLogService::log('uat', 'feedback_updated', $feedback, $old, $feedback->toArray(), $request);

        return new UatFeedbackResource($feedback->load(['user.role', 'user.roles', 'assignee.role', 'assignee.roles', 'statusHistory.user', 'comments.user']));
    }

    public function storeFeedbackComment(Request $request, UatFeedback $feedback): UatFeedbackResource
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(UatFeedbackComment::TYPES)],
            'comment' => ['required', 'string', 'max:5000'],
        ]);

        $comment = $feedback->comments()->create([
            ...$data,
            'user_id' => $request->user()?->id,
        ]);
        AuditLogService::log('uat', 'feedback_comment_created', $comment, null, $comment->toArray(), $request);

        return new UatFeedbackResource($feedback->load(['user.role', 'user.roles', 'assignee.role', 'assignee.roles', 'statusHistory.user', 'comments.user']));
    }

    public function downloadResultScreenshot(Request $request, UatTestResult $result): StreamedResponse
    {
        abort_unless($result->screenshot_path && Storage::disk('local')->exists($result->screenshot_path), 404);
        return Storage::disk('local')->download($result->screenshot_path);
    }

    public function downloadFeedbackScreenshot(Request $request, UatFeedback $feedback): StreamedResponse
    {
        abort_unless($feedback->screenshot_path && Storage::disk('local')->exists($feedback->screenshot_path), 404);
        return Storage::disk('local')->download($feedback->screenshot_path);
    }

    public function exportRuns(Request $request): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['run_id', 'role', 'tester', 'scenario', 'status', 'comment', 'actual_result']);
            UatTestRun::query()->with(['tester', 'results'])->orderBy('id')->chunk(100, function ($runs) use ($out): void {
                foreach ($runs as $run) {
                    foreach ($run->results as $result) {
                        fputcsv($out, [$run->id, $run->role_code, $run->tester?->email, $result->scenario_code, $result->status, $result->comment, $result->actual_result]);
                    }
                }
            });
            fclose($out);
        }, 'uat-results.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportIssues(Request $request): StreamedResponse
    {
        return response()->streamDownload(function () use ($request): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'role', 'category', 'severity', 'title', 'status', 'page_url', 'version', 'build']);
            $query = UatFeedback::query()->orderBy('id');
            if ($request->boolean('failed_only')) {
                $query->whereIn('status', ['new', 'confirmed', 'in_progress', 'retest']);
            }
            $query->chunk(100, function ($items) use ($out): void {
                foreach ($items as $item) {
                    fputcsv($out, [$item->id, $item->role_code, $item->category, $item->severity, $item->title, $item->status, $item->page_url, $item->app_version, $item->build_hash]);
                }
            });
            fclose($out);
        }, $request->boolean('failed_only') ? 'uat-open-issues.csv' : 'uat-feedback.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
