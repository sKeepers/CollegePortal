<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeletionRequest;
use App\Models\Group;
use App\Models\JournalEditRequest;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\AttendanceAnalysisService;
use Illuminate\Http\Request;

/**
 * Мобильный кабинет администратора: показатели дня и счётчики того, что ждёт
 * решения.
 *
 * Сам список входящих кабинет **не собирает**: его собирает общий
 * `frontend/src/services/adminInbox.js`, которым пользуется и «колокольчик» на
 * десктопе. Требование задачи — чтобы список совпадал с десктопным, а
 * совпадение, написанное дважды, рано или поздно разъезжается. Здесь остаются
 * только счётчики: телефон показывает их сразу, не дожидаясь четырёх запросов.
 *
 * Счётчик показывается лишь тому, кто может по нему что-то сделать: число
 * запросов на переоткрытие журнала без права `journal.reopen` — это ровно та
 * кнопка, которая не сработает.
 */
class MobileAdminController extends Controller
{
    public function __construct(private readonly AttendanceAnalysisService $analysis)
    {
    }

    public function show(Request $request): array
    {
        $user = $request->user();
        $abilities = [
            'review_journal_requests' => $user->hasPermission('journal.reopen'),
            'review_deletion_requests' => $user->hasPermission('trash.manage'),
            'search_people' => $user->hasPermission('people.view'),
            'view_attendance' => $user->hasPermission('attendance.reports'),
        ];

        return ['data' => [
            'counts' => [
                'students' => Student::query()->where('status', 'active')->count(),
                'teachers' => Teacher::query()->where('is_active', true)->count(),
                'groups' => Group::query()->count(),
                'users' => User::query()->where('is_active', true)->count(),
            ],
            // Присутствие и опоздания считает разбор посещаемости — тот же, что
            // питает рабочий стол. Своего расчёта здесь нет и быть не должно.
            'today' => $abilities['view_attendance'] ? $this->analysis->dashboardSummary() : null,
            'pending' => array_filter([
                'journal_edit_requests' => $abilities['review_journal_requests']
                    ? JournalEditRequest::query()->where('status', JournalEditRequest::STATUS_PENDING)->count()
                    : null,
                'deletion_requests' => $abilities['review_deletion_requests']
                    ? DeletionRequest::query()->pending()->count()
                    : null,
            ], static fn (mixed $value): bool => $value !== null),
            'abilities' => $abilities,
        ]];
    }
}
