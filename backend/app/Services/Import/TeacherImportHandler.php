<?php

namespace App\Services\Import;

use App\Models\Person;
use App\Models\Teacher;
use App\Services\AccountProvisioningService;
use App\Services\Admissions\SnilsService;
use App\Services\PersonService;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Загрузка преподавателей.
 *
 * Шаблон до 10.08.2026 был самым бедным из всех: девять колонок, ни даты
 * рождения, ни СНИЛС, ни адреса. Выгрузка при этом отдавала машинные имена
 * полей, и обратно тем же файлом карточка не восстанавливалась.
 *
 * Общие данные человека — ФИО, телефон, email, дата рождения, адрес, СНИЛС —
 * пишутся через `PersonService`, а не в копию у преподавателя: копия в
 * `teachers` приходит зеркалом. Это правило уже нарушалось, и правка ФИО
 * «сохранялась», а потом возвращалась обратно.
 */
class TeacherImportHandler extends AbstractImportHandler
{
    public function __construct(
        private readonly AccountProvisioningService $accounts,
        private readonly PersonService $people,
        private readonly SnilsService $snils,
    ) {
    }

    public function type(): string { return 'teachers'; }
    public function label(): string { return 'Преподаватели'; }
    public function modelClass(): string { return Teacher::class; }
    public function keyFields(): array { return ['email']; }

    public function fields(): array
    {
        return [
            'last_name' => ['label' => 'Фамилия', 'required' => true, 'aliases' => ['фамилия', 'last_name']],
            'first_name' => ['label' => 'Имя', 'required' => true, 'aliases' => ['имя', 'first_name']],
            'middle_name' => ['label' => 'Отчество', 'required' => false, 'aliases' => ['отчество', 'middle_name']],
            'birth_date' => ['label' => 'Дата рождения', 'required' => false, 'aliases' => ['дата рождения', 'birth_date']],
            'snils' => ['label' => 'СНИЛС', 'required' => false, 'aliases' => ['снилс', 'snils']],
            'phone' => ['label' => 'Телефон', 'required' => false, 'aliases' => ['телефон', 'phone']],
            'email' => ['label' => 'Email', 'required' => false, 'aliases' => ['email', 'почта', 'e-mail']],
            'address' => ['label' => 'Адрес', 'required' => false, 'aliases' => ['адрес', 'address']],
            'position' => ['label' => 'Должность', 'required' => false, 'aliases' => ['должность', 'position']],
            'department' => ['label' => 'Отделение', 'required' => false, 'aliases' => ['отделение', 'кафедра', 'department']],
            'is_active' => ['label' => 'Активен', 'required' => false, 'aliases' => ['активен', 'is_active']],
            'auto_account' => ['label' => 'Создать учетную запись', 'required' => false, 'aliases' => ['создать учетную запись', 'auto_account']],
        ];
    }

    public function templateHeaders(): array
    {
        return ['Фамилия', 'Имя', 'Отчество', 'Дата рождения', 'СНИЛС', 'Телефон', 'Email', 'Адрес', 'Должность', 'Отделение', 'Активен', 'Создать учетную запись'];
    }

    public function templateExample(): array
    {
        return ['Петрова', 'Анна', 'Викторовна', '15.04.1985', '123-456-789 00', '+79990000010', 'teacher@example.test', 'г. Ставрополь, ул. Мира, 1', 'Преподаватель', 'Музыкальное отделение', 'да', 'нет'];
    }

    public function prepare(array $data): array
    {
        $data['birth_date'] = $this->normalizeDate($data['birth_date'] ?? null);
        // Здесь только цифры: неверное контрольное число — ошибка строки, а не
        // повод уронить всю загрузку исключением. Проверка — в
        // businessValidationErrors, как это сделано у студентов.
        $data['snils'] = preg_replace('/\D+/', '', (string) ($data['snils'] ?? '')) ?: null;
        $data['is_active'] = $this->booleanValue($data['is_active'] ?? true);
        $data['auto_account'] = $this->booleanValue($data['auto_account'] ?? false);

        return $data;
    }

    public function rules(): array
    {
        return [
            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['nullable', 'date'],
            'snils' => ['nullable', 'string', 'max:32'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'position' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'auto_account' => ['boolean'],
        ];
    }

    public function findExisting(array $data): ?Model
    {
        return ! empty($data['email']) ? Teacher::where('email', $data['email'])->first() : null;
    }

    public function businessValidationErrors(array $data): array
    {
        $errors = [];

        // Колонка «Создать учётную запись» отказывает, а не создаёт молча:
        // почему именно так — в `AccountProvisioningService::ACCOUNTS_ARE_ISSUED_SEPARATELY`.
        // Отказ приходит на предпросмотре, до единой записи.
        if ($data['auto_account'] ?? false) {
            $errors['auto_account'] = [AccountProvisioningService::ACCOUNTS_ARE_ISSUED_SEPARATELY];
        }

        if (filled($data['snils'] ?? null)) {
            try {
                $this->snils->normalize($data['snils']);
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $errors['snils'] = $exception->errors()['snils'] ?? [$exception->getMessage()];
            }
        }

        return $errors;
    }

    public function import(array $data, string $mode): string
    {
        $existing = $this->findExisting($data);

        if ($mode === self::MODE_UPDATE) {
            if (! $existing) {
                return 'skipped';
            }

            $this->applyPerson($existing, $data);
            $existing->update($this->payload($data, true));

            return 'updated';
        }

        if ($existing) {
            if ($mode === self::MODE_SKIP_DUPLICATES) {
                return 'skipped';
            }

            throw new RuntimeException('Дубликат по ключевому полю.');
        }

        $teacher = Teacher::create($this->payload($data));
        $this->applyPerson($teacher, $data);

        return 'created';
    }

    /**
     * Человек для преподавателя: ищется по СНИЛС, иначе заводится новый.
     * Дата рождения, адрес и СНИЛС своих колонок у преподавателя не имеют и
     * живут только здесь, поэтому без Person эти поля из файла просто пропали
     * бы — что и происходило, пока их не было в шаблоне.
     *
     * Публичный, потому что тем же путём кладёт данные и собственный CSV-импорт
     * преподавателей: две точки загрузки на одном файле обязаны давать один
     * результат, а не расходиться в том, куда попал СНИЛС.
     */
    public function applyPerson(Teacher $teacher, array $data): void
    {
        $shared = array_filter([
            'last_name' => $data['last_name'] ?? null,
            'first_name' => $data['first_name'] ?? null,
            'middle_name' => $data['middle_name'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'snils' => $data['snils'] ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $person = $teacher->person ?: $this->findPersonBySnils($data['snils'] ?? null);

        if (! $person) {
            $person = $this->people->createPerson($shared);
        } else {
            $this->people->updateSharedData($person, $shared);
        }

        if ((int) $teacher->person_id !== (int) $person->id) {
            $teacher->forceFill(['person_id' => $person->id])->save();
        }

        $teacher->refresh();
    }

    private function findPersonBySnils(?string $snils): ?Person
    {
        $hash = $this->snils->hash($snils);

        return $hash === null ? null : Person::query()->where('snils_hash', $hash)->first();
    }

    protected function virtualFields(): array
    {
        // Ни СНИЛС, ни дата рождения, ни адрес не колонки преподавателя: они
        // принадлежат человеку и записываются через PersonService.
        return ['auto_account', 'birth_date', 'snils', 'address'];
    }
}
