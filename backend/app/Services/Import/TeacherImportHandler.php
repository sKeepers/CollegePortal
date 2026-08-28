<?php

namespace App\Services\Import;

use App\Models\Person;
use App\Models\Teacher;
use App\Services\AccountProvisioningService;
use App\Services\Admissions\SnilsService;
use App\Services\PersonService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
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

    /**
     * Преподаватель, к которому относится строка.
     *
     * Ключ обновления объявлен один — email, и до 28.08.2026 других не было: строка
     * без email не находила никого и заводила нового преподавателя **каждую загрузку**.
     * В выгрузке кадров, которую принёс владелец 28.08, email пуст у всех 175 человек,
     * то есть вторая загрузка того же файла удвоила бы список целиком — и обещание
     * `docs/import-templates/README.md` «уже загруженные строки обновятся, а не
     * задвоятся» для преподавателей не выполнялось.
     *
     * Поэтому при пустом email строка ищет человека — так же, как это давно делает
     * загрузка сотрудников (`EmployeeImportHandler::findExisting`), — и берёт его
     * карточку преподавателя. Неоднозначное ФИО сюда не доходит: оно отсеивается
     * в `businessValidationErrors` до единой записи.
     */
    public function findExisting(array $data): ?Model
    {
        if (! empty($data['email']) && ($teacher = Teacher::where('email', $data['email'])->first())) {
            return $teacher;
        }

        $person = $this->matchPerson($data);

        return $person ? Teacher::where('person_id', $person->id)->first() : null;
    }

    public function businessValidationErrors(array $data): array
    {
        $errors = [];

        // Двух людей с одним ФИО строка без email различить нечем, и любой выбор
        // здесь — угадывание: карточка ушла бы не тому человеку. Отказ приходит на
        // предпросмотре, до единой записи.
        if (empty($data['email']) && $this->matchingPeople($data)->count() > 1) {
            $errors['last_name'] = ['В портале несколько человек с таким ФИО. Уточните строку: email, СНИЛС или дата рождения.'];
        }

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

        $person = $teacher->person ?: $this->matchPerson($data);

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

    /**
     * Человек, которого описывает строка, или `null`, если такого в портале нет.
     *
     * Порядок тот же, что у сотрудников: СНИЛС, затем ФИО с датой рождения, затем
     * одно ФИО. Совпадений может оказаться несколько — тогда выбирать нельзя, и
     * метод возвращает `null`: строку к этому времени уже остановила проверка
     * `businessValidationErrors`, а вызов со стороны (`TeacherCsvService`) заведёт
     * нового человека, но не привяжет карточку к чужому.
     *
     * Совпадение ищется среди всех людей, включая студентов. Полный тёзка студента
     * и преподавателя получил бы одну карточку человека на двоих: на 28.08.2026 из
     * 243 строк выгрузки кадров со студентами не совпал никто — замерено запросом к
     * стенду, — а если такой случай появится, его остановит та же проверка на
     * неоднозначность.
     */
    private function matchPerson(array $data): ?Person
    {
        $people = $this->matchingPeople($data);

        return $people->count() === 1 ? $people->first() : null;
    }

    /**
     * Все люди, подходящие под строку. Больше одного значит «непонятно кто».
     *
     * @return \Illuminate\Support\Collection<int, Person>
     */
    private function matchingPeople(array $data): Collection
    {
        if ($person = $this->findPersonBySnils($data['snils'] ?? null)) {
            return collect([$person]);
        }

        if (empty($data['last_name']) || empty($data['first_name'])) {
            return collect();
        }

        $byName = Person::query()
            ->where('last_name', $data['last_name'])
            ->where('first_name', $data['first_name'])
            ->where('middle_name', $data['middle_name'] ?? null);

        if (filled($data['birth_date'] ?? null)) {
            $byBirthDate = (clone $byName)->where('birth_date', $data['birth_date'])->get();

            if ($byBirthDate->isNotEmpty()) {
                return $byBirthDate;
            }
        }

        return $byName->get();
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
