<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeAccountPasswordRequest;
use App\Http\Requests\UpdateAccountContactsRequest;
use App\Http\Resources\RfidCardResource;
use App\Models\Person;
use App\Services\AuditLogService;
use App\Services\PersonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Раздел «Моя учётная запись»: то немногое, что человек вправе изменить о себе сам.
 *
 * Прав здесь нет намеренно — распоряжаться своей почтой, телефоном и паролем может
 * любой вошедший, независимо от роли. До этого раздела личная страница в портале была
 * ровно одна и только для студентов, а сменить пароль можно было лишь через
 * администратора.
 */
class AccountController extends Controller
{
    public function __construct(private readonly PersonService $people)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $person = $this->personOf($user);

        return response()->json(['data' => [
            'name' => $user->name,
            'login' => $user->email ?: $user->username,
            'role' => $user->role?->name,
            'last_login_at' => $user->last_login_at?->toISOString(),
            // Пароль выдан порталом, своего человек ещё не заводил: раздел показывает
            // предложение сменить его, а не молча ждёт, что человек догадается сам.
            'must_change_password' => (bool) $user->must_change_password,
            // Контакты берутся у человека, а не у учётной записи: это общие данные,
            // и они же показываются в карточках студента, преподавателя и сотрудника.
            'has_person' => $person !== null,
            'email' => $person?->email,
            'phone' => $person?->phone,
            // Свои карты — без проверки права: это собственные данные человека.
            // Чужих здесь быть не может по построению: карты берутся у той самой
            // карточки человека, к которой привязана учётная запись.
            'rfid_cards' => $person === null
                ? []
                : RfidCardResource::collection($person->rfidCards()->orderByDesc('issued_at')->get())->resolve(),
        ]]);
    }

    public function updateContacts(UpdateAccountContactsRequest $request): JsonResponse
    {
        $person = $this->personOf($request->user());

        if ($person === null) {
            return $this->noPersonResponse();
        }

        $before = ['email' => $person->email, 'phone' => $person->phone];

        // Через PersonService, а не напрямую: почта и телефон принадлежат человеку,
        // и копии в карточках преподавателя и студента обязаны прийти зеркалом.
        // Пустое значение здесь означает «очистить» — человек видит, что стирает.
        $person = $this->people->updateSharedData($person, $request->validated());

        AuditLogService::log('auth', 'account_contacts_updated', $person, $before, [
            'email' => $person->email,
            'phone' => $person->phone,
        ], $request, $request->user());

        return response()->json(['data' => ['email' => $person->email, 'phone' => $person->phone]]);
    }

    public function changePassword(ChangeAccountPasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->validated()['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Текущий пароль указан неверно.',
                'errors' => ['current_password' => ['Текущий пароль указан неверно.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Отметка снимается здесь и только здесь: человек завёл свой пароль, и
        // предлагать ему это при каждом входе больше не нужно.
        $wasIssued = (bool) $user->must_change_password;
        $user->forceFill([
            'password' => $request->validated()['password'],
            'must_change_password' => false,
            // Свой пароль сроку не подлежит: срок был у выданного, и человек
            // его только что заменил.
            'password_expires_at' => null,
        ])->save();

        // В журнал не попадает ни старый пароль, ни новый — только сам факт смены.
        AuditLogService::log('auth', 'account_password_changed', $user, null, [
            'self_service' => true,
            'replaced_issued_password' => $wasIssued,
        ], $request, $user);

        return response()->json(['message' => 'Пароль изменён.']);
    }

    /**
     * Человек за учётной записью. Служебные учётки его не имеют — им и менять нечего.
     */
    private function personOf($user): ?Person
    {
        if ($user->person_id && $user->person_type === 'person') {
            return Person::find($user->person_id);
        }

        return $user->student?->person ?? $user->teacher?->person;
    }

    private function noPersonResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'К учётной записи не привязана личная карточка, поэтому контакты хранить негде. Обратитесь к администратору.',
            'code' => 'account_without_person',
        ], Response::HTTP_CONFLICT);
    }
}
