<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DormAbsence;
use App\Models\DormConductRecord;
use App\Models\DormIncident;
use App\Models\DormRoom;
use App\Models\DormSocialRecord;
use App\Services\DormNightAbsenceService;
use App\Services\DormPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Сводка «что сегодня» — с чего начать день.
 *
 * Комендант не должен обходить пять вкладок, чтобы понять, чем заняться:
 * в конце августа, когда идёт заселение, это и есть цена экрана.
 *
 * Сводки две, и они не смешиваются: у коменданта — места, ночь, оплата,
 * происшествия; у заместителя — провинности и социальный паспорт. Разный
 * доступ, разные экраны. Каждый блок вдобавок закрыт своим правом изнутри:
 * заместитель, открывший сводку коменданта, оплаты в ней не увидит.
 *
 * Отдельное правило, ради которого всё и затевалось: **ноль показывается
 * только тогда, когда он посчитан**. Ночь, которую не пересчитывали, говорит
 * «не пересчитывалась», а не «все на месте» — иначе сводка врёт увереннее, чем
 * пустой экран.
 */
class DormTodayController extends Controller
{
    public function warden(Request $request, DormPaymentService $payments, DormNightAbsenceService $absences): JsonResponse
    {
        $user = $request->user();
        $night = Carbon::today()->subDay();
        $data = [];

        $rooms = DormRoom::query()->where('is_active', true)->withCount('currentPlacements')->get();
        $data['places'] = [
            'rooms' => $rooms->count(),
            'capacity' => (int) $rooms->sum('capacity'),
            'occupied' => (int) $rooms->sum('current_placements_count'),
            'free' => (int) $rooms->sum(fn (DormRoom $room) => max(0, $room->capacity - $room->current_placements_count)),
        ];

        if ($user?->hasPermission('dorm.absences.view')) {
            // Отметка, а не «строк ноль»: посчитанная ночь без отсутствий и
            // непосчитанная выглядят одинаково, и сводка врала бы уверенно.
            $through = $absences->calculatedThrough();
            $calculated = $through !== null && $through->toDateString() >= $night->toDateString();

            $rows = DormAbsence::query()
                ->with('student.group')
                ->whereDate('night_of', $night->toDateString())
                ->orderBy('student_id')
                ->limit(20)
                ->get();

            $data['night'] = [
                'night_of' => $night->toDateString(),
                // Ноль, который никто не считал, — это не «все на месте».
                'calculated' => $calculated,
                'count' => $calculated ? $rows->count() : null,
                'people' => $rows->map(fn (DormAbsence $row) => [
                    'student_id' => $row->student_id,
                    'full_name' => $this->fullName($row->student),
                    'group' => $row->student?->group?->name,
                    'left_at' => $row->left_at?->toISOString(),
                ])->all(),
            ];
        }

        if ($user?->hasPermission('dorm.payments.view')) {
            $summary = $payments->summary();
            $overdue = $summary->filter(fn (array $row) => $row['overdue_days'] > 0 || $row['never_paid'])->values();

            $data['payments'] = [
                'residents' => $summary->count(),
                'overdue' => $overdue->count(),
                'never_paid' => $summary->filter(fn (array $row) => $row['never_paid'])->count(),
                'worst' => $overdue->take(10)->all(),
            ];
        }

        if ($user?->hasPermission('dorm.incidents.view')) {
            $since = Carbon::now()->subDay();
            $incidents = DormIncident::query()
                ->with('room')
                ->where('happened_at', '>=', $since)
                ->orderByDesc('happened_at')
                ->limit(10)
                ->get();

            $data['incidents'] = [
                'since' => $since->toISOString(),
                'count' => $incidents->count(),
                'rows' => $incidents->map(fn (DormIncident $incident) => [
                    'id' => $incident->id,
                    'happened_at' => $incident->happened_at?->toISOString(),
                    'summary' => $incident->summary,
                    'room' => $incident->room?->number,
                ])->all(),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * Сводка заместителя — своя и другая.
     *
     * Смешивать её с комендантской нельзя: это два разных доступа, и общий
     * экран пришлось бы резать правами до неузнаваемости.
     */
    public function upbringing(Request $request): JsonResponse
    {
        $user = $request->user();
        $from = Carbon::today()->subDays(30);
        $data = ['from' => $from->toDateString()];

        if ($user?->hasPermission('dorm.conduct.view')) {
            $today = Carbon::today()->toDateString();

            $active = DormConductRecord::query()
                ->with('student.group')
                ->whereNull('parent_id')
                // Действующая — та, что ещё не погасла. Скобки обязательны:
                // без них `orWhere` вытащил бы и дополнения, и погасшие.
                ->where(function ($query) use ($today): void {
                    $query->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today);
                })
                ->orderByDesc('happened_on')
                ->limit(20)
                ->get();

            $recent = DormConductRecord::query()
                ->whereNull('parent_id')
                ->whereDate('happened_on', '>=', $from->toDateString())
                ->count();

            $data['conduct'] = [
                'recent' => $recent,
                'rows' => $active->map(fn (DormConductRecord $record) => [
                    'id' => $record->id,
                    'happened_on' => $record->happened_on?->toDateString(),
                    'summary' => $record->summary,
                    'full_name' => $this->fullName($record->student),
                    'group' => $record->student?->group?->name,
                    'expires_on' => $record->expires_on?->toDateString(),
                ])->all(),
            ];
        }

        if ($user?->hasPermission('dorm.social.view')) {
            $open = DormSocialRecord::query()->whereNull('closed_on')->get();

            $data['social'] = [
                'people' => $open->pluck('student_id')->unique()->count(),
                'by_category' => $open->groupBy('category')->map->count()->map(fn (int $count, string $category) => [
                    'category' => $category,
                    'label' => DormSocialRecord::categoryLabel($category),
                    'count' => $count,
                ])->values()->all(),
            ];
        }

        return response()->json(['data' => $data]);
    }

    private function fullName(?object $student): string
    {
        return trim(implode(' ', array_filter([
            $student?->last_name,
            $student?->first_name,
            $student?->middle_name,
        ])));
    }
}
