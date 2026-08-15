<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Student;
use App\Services\CuratorScopeService;
use App\Services\GroupRosterService;
use App\Services\StudentPerformanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Своя группа глазами куратора: состав и успеваемость.
 *
 * Раздел открывает право `journal.view` — то самое, по которому куратор видит
 * журнал, — а чью группу видно, решает `groups.curator_id` на каждом запросе.
 * Отдельного права нет намеренно: новое право обязано прийти миграцией и
 * сидером сразу, а сидер лежит за другой областью; здесь же ничего нового не
 * открывается — куратор и так видит и оценки, и состав своей группы.
 *
 * Кто видит журнал целиком (`journal.view_all` — директор, заместитель,
 * администратор), тот видит здесь любую группу. Это не послабление, а то же
 * правило, что и в журнале: две разные границы для одних и тех же данных
 * однажды разойдутся.
 *
 * Эндпоинты общие для компьютера и телефона. Мобильный кабинет куратора зовёт
 * их же: считать успеваемость дважды — верный способ показать на двух экранах
 * два разных средних балла.
 */
class CuratorGroupController extends Controller
{
    public function __construct(
        private readonly CuratorScopeService $scope,
        private readonly StudentPerformanceService $performance,
        private readonly GroupRosterService $roster,
    ) {
    }

    /** Группы, за которые человек отвечает. */
    public function index(Request $request): array
    {
        $user = $request->user();

        $query = Group::query()->orderBy('name');

        if (! $user->hasPermission('journal.view_all')) {
            $query->whereIn('id', $this->scope->curatedGroupIds($user)->all());
        }

        $groups = $query->get();

        return ['data' => [
            'groups' => $groups->map(fn (Group $group): array => [
                'id' => $group->id,
                'name' => $group->name,
                'course' => $group->course,
                'specialty' => $group->specialty,
                'students_count' => $this->roster->query($group)->count(),
            ])->values()->all(),
            'message' => $groups->isEmpty() ? 'За вами не закреплено ни одной группы.' : '',
        ]];
    }

    /** Успеваемость группы за период. */
    public function performance(Request $request, Group $group): array
    {
        $this->authorizeGroup($request, $group);

        $filters = Validator::make($request->query(), [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ], [
            'date_to.after_or_equal' => 'Дата окончания должна быть не раньше даты начала.',
        ])->validate();

        return ['data' => $this->performance->forGroup(
            $group,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        )];
    }

    /** Состав группы с контактами: кому звонить, когда студент пропал. */
    public function students(Request $request, Group $group): array
    {
        $this->authorizeGroup($request, $group);

        $students = $this->roster->active($group);

        return ['data' => [
            'group' => ['id' => $group->id, 'name' => $group->name, 'course' => $group->course],
            'students' => $students->map(fn (Student $student): array => [
                'id' => $student->id,
                'name' => collect([$student->last_name, $student->first_name, $student->middle_name])->filter()->join(' '),
                'phone' => $student->phone,
                'email' => $student->email,
                'status' => $student->status,
            ])->values()->all(),
        ]];
    }

    /**
     * Единственный вход к группе.
     *
     * Идентификатор приходит в пути, и подстановка чужого — первое, что
     * попробует любопытный. Отсутствие карточки преподавателя тоже приводит
     * сюда: раздел откроется, группа — нет.
     */
    private function authorizeGroup(Request $request, Group $group): void
    {
        $user = $request->user();

        if ($user->hasPermission('journal.view_all')) {
            return;
        }

        if (! $this->scope->curates($user, $group)) {
            abort(403, 'Эта группа не закреплена за вами.');
        }
    }
}
