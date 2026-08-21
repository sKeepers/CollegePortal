<?php

namespace App\Services\Import;

use App\Models\Student;
use App\Services\AccountProvisioningService;
use App\Services\Admissions\EducationDocumentService;
use App\Services\Admissions\IdentityDocumentService;
use App\Services\Admissions\SnilsService;
use App\Services\Import\Concerns\ResolvesImportRelations;
use App\Services\StudentPersonService;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class StudentImportHandler extends AbstractImportHandler
{
    use ResolvesImportRelations;

    public function __construct(
        private readonly SnilsService $snils,
        private readonly StudentPersonService $people,
        private readonly IdentityDocumentService $identityDocuments,
        private readonly EducationDocumentService $educationDocuments,
        private readonly ?AccountProvisioningService $accounts = null,
    ) {
    }

    public function type(): string { return 'students'; }
    public function label(): string { return 'Студенты'; }
    public function modelClass(): string { return Student::class; }
    public function keyFields(): array { return ['snils', 'email', 'last_name', 'first_name', 'birth_date']; }
    public function fields(): array { return ['last_name'=>['label'=>'Фамилия','required'=>true,'aliases'=>['фамилия','last_name']],'first_name'=>['label'=>'Имя','required'=>true,'aliases'=>['имя','first_name']],'middle_name'=>['label'=>'Отчество','required'=>false,'aliases'=>['отчество','middle_name']],'group_id'=>['label'=>'ID группы','required'=>false,'aliases'=>['group_id','id группы']],'group_name'=>['label'=>'Группа','required'=>false,'aliases'=>['группа','group','group_name']],'course'=>['label'=>'Курс','required'=>false,'aliases'=>['курс','номер курса','год обучения','course']],'specialty'=>['label'=>'Специальность','required'=>false,'aliases'=>['специальность','specialty']],'education_form'=>['label'=>'Форма обучения','required'=>false,'aliases'=>['форма обучения','очная/заочная','education_form']],'birth_date'=>['label'=>'Дата рождения','required'=>false,'aliases'=>['дата рождения','birth_date']],'phone'=>['label'=>'Телефон','required'=>false,'aliases'=>['телефон','phone']],'email'=>['label'=>'Email','required'=>false,'aliases'=>['email','почта','e-mail']],'snils'=>['label'=>'СНИЛС','required'=>false,'aliases'=>['снилс','snils']],'address'=>['label'=>'Адрес','required'=>false,'aliases'=>['адрес','домашний адрес','address']],'status'=>['label'=>'Статус','required'=>false,'aliases'=>['статус','status']],'enrollment_date'=>['label'=>'Дата зачисления','required'=>false,'aliases'=>['дата зачисления','enrollment_date']],'enrollment_order_number'=>['label'=>'Приказ о зачислении','required'=>false,'aliases'=>['приказ о зачислении','номер приказа','enrollment_order_number']],'enrollment_order_date'=>['label'=>'Дата приказа о зачислении','required'=>false,'aliases'=>['дата приказа о зачислении','enrollment_order_date']],'personal_file_number'=>['label'=>'Номер личного дела','required'=>false,'aliases'=>['номер личного дела','личное дело','алфавитный классификатор','номер зачетной книжки','номер зачётной книжки','зачетная книжка','зачётная книжка','personal_file_number']],'passport_series'=>['label'=>'Серия паспорта','required'=>false,'aliases'=>['серия паспорта','passport_series']],'passport_number'=>['label'=>'Номер паспорта','required'=>false,'aliases'=>['номер паспорта','passport_number']],'passport_issue_date'=>['label'=>'Дата выдачи паспорта','required'=>false,'aliases'=>['дата выдачи паспорта','passport_issue_date']],'passport_issued_by'=>['label'=>'Кем выдан паспорт','required'=>false,'aliases'=>['кем выдан паспорт','кем выдан','passport_issued_by']],'education_document_type'=>['label'=>'Тип документа об образовании','required'=>false,'aliases'=>['тип документа об образовании','education_document_type']],'education_document_series'=>['label'=>'Серия документа об образовании','required'=>false,'aliases'=>['серия документа об образовании','education_document_series']],'education_document_number'=>['label'=>'Номер документа об образовании','required'=>false,'aliases'=>['номер документа об образовании','education_document_number']],'education_document_issue_date'=>['label'=>'Дата выдачи документа об образовании','required'=>false,'aliases'=>['дата выдачи документа об образовании','education_document_issue_date']],'education_document_organization'=>['label'=>'Учебное заведение','required'=>false,'aliases'=>['учебное заведение','кем выдан документ об образовании','education_document_organization']],'education_graduation_year'=>['label'=>'Год окончания','required'=>false,'aliases'=>['год окончания','education_graduation_year']],'auto_account'=>['label'=>'Создать учетную запись','required'=>false,'aliases'=>['создать учетную запись','auto_account']]]; }
    public function templateHeaders(): array { return ['Фамилия','Имя','Отчество','Группа','Дата рождения','Телефон','Email','СНИЛС','Статус','Дата зачисления','Курс','Специальность','Форма обучения','Адрес','Приказ о зачислении','Дата приказа о зачислении','Номер личного дела','Серия паспорта','Номер паспорта','Дата выдачи паспорта','Кем выдан паспорт','Тип документа об образовании','Серия документа об образовании','Номер документа об образовании','Дата выдачи документа об образовании','Учебное заведение','Год окончания','Создать учетную запись']; }
    public function templateExample(): array { return ['Иванов','Дмитрий','Сергеевич','ИСП-101','12.05.2009','+79990000002','student@example.test','','active','01.09.2026','1','Инструментальное исполнительство','Очная','г. Ставрополь, ул. Примерная, д. 1','91','15.08.2026','330','0712','345678','20.05.2025','ГУ МВД России','Аттестат об основном общем образовании','АБ','123456','20.06.2026','МБОУ СОШ № 1','2026','нет']; }
    public function prepare(array $data): array { $data['birth_date']=$this->normalizeDate($data['birth_date']??null); $data['enrollment_date']=$this->normalizeDate($data['enrollment_date']??null); $data['enrollment_order_date']=$this->normalizeDate($data['enrollment_order_date']??null); $data['passport_issue_date']=$this->normalizeDate($data['passport_issue_date']??null); $data['education_document_issue_date']=$this->normalizeDate($data['education_document_issue_date']??null); $data['snils']=preg_replace('/\D+/', '', (string) ($data['snils']??'')) ?: null; $data['group_id']=$this->resolveGroupId($data['group_id']??null,$data['group_name']??null); $data['status']=$this->studentStatus($data['status']??null); $data['auto_account']=$this->booleanValue($data['auto_account']??false); return $data; }
    public function rules(): array { return ['group_id'=>['required','integer','exists:groups,id'],'last_name'=>['required','string','max:255'],'first_name'=>['required','string','max:255'],'middle_name'=>['nullable','string','max:255'],'course'=>['nullable','integer','min:1','max:6'],'specialty'=>['nullable','string','max:255'],'education_form'=>['nullable','string','max:80'],'birth_date'=>['nullable','date'],'phone'=>['nullable','string','max:50'],'email'=>['nullable','email','max:255'],'snils'=>['nullable','string','max:32'],'address'=>['nullable','string','max:2000'],'status'=>['required','in:active,academic_leave,graduated,expelled'],'enrollment_date'=>['nullable','date'],'enrollment_order_number'=>['nullable','string','max:100'],'enrollment_order_date'=>['nullable','date'],'personal_file_number'=>['nullable','string','max:50'],'passport_series'=>['nullable','string','max:20'],'passport_number'=>['nullable','string','max:100'],'passport_issue_date'=>['nullable','date'],'passport_issued_by'=>['nullable','string','max:1000'],'education_document_type'=>['nullable','string','max:255'],'education_document_series'=>['nullable','string','max:20'],'education_document_number'=>['nullable','string','max:100'],'education_document_issue_date'=>['nullable','date'],'education_document_organization'=>['nullable','string','max:1000'],'education_graduation_year'=>['nullable','integer','min:1950','max:2100'],'auto_account'=>['boolean']]; }
    public function findExisting(array $data): ?Model { $matches=collect(); if (!empty($data['snils'])) $matches=$matches->merge(Student::where('snils',$data['snils'])->get()); if (!empty($data['email'])) $matches=$matches->merge(Student::where('email',$data['email'])->get()); if (!empty($data['birth_date'])) $matches=$matches->merge(Student::where('last_name',$data['last_name'])->where('first_name',$data['first_name'])->where('birth_date',$data['birth_date'])->get()); if ($matches->isEmpty()) $matches=$matches->merge(Student::where('group_id',$data['group_id'])->where('last_name',$data['last_name'])->where('first_name',$data['first_name'])->where('middle_name',$data['middle_name'] ?? null)->get()); $matches=$matches->unique('id')->values(); if ($matches->count()>1) throw new RuntimeException('Найдено несколько совпадающих студентов. Уточните СНИЛС, email или дату рождения.'); return $matches->first(); }

    public function import(array $data, string $mode): string
    {
        $existing = $this->findExisting($data);

        if ($mode === self::MODE_UPDATE) {
            if (! $existing) {
                return 'skipped';
            }

            $existing->update($this->payload($data, true));
            $this->syncDocuments($existing, $data);

            return 'updated';
        }

        if ($existing) {
            if ($mode === self::MODE_SKIP_DUPLICATES) {
                return 'skipped';
            }

            throw new RuntimeException('Дубликат по ключевому полю.');
        }

        $student = Student::create($this->payload($data));
        $this->syncDocuments($student, $data);

        if ($data['auto_account'] && $this->accounts) {
            $this->accounts->provision($student);
        }

        return 'created';
    }

    /**
     * Проверки, которые нельзя выразить правилами формы.
     *
     * Номер личного дела обязан быть свободен **в пределах своей буквы**: у
     * каждой буквы алфавита своя нумерация, поэтому Иванов и Петров могут
     * носить один номер, а два Ивановых — нет. Строка с занятым номером
     * отклоняется до записи, чтобы загрузка не поставила второе такое же дело.
     */
    public function businessValidationErrors(array $data): array
    {
        $errors = [];

        if (filled($data['snils'] ?? null)) {
            try {
                $this->snils->normalize($data['snils']);
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $errors['snils'] = $exception->errors()['snils'] ?? [$exception->getMessage()];
            }
        }

        if (filled($data['personal_file_number'] ?? null)) {
            $messages = [];
            (new \App\Rules\FreePersonalFileNumber($data['last_name'] ?? null))
                ->validate('personal_file_number', $data['personal_file_number'], function (string $message) use (&$messages): void {
                    $messages[] = $message;
                });

            if ($messages !== []) {
                $errors['personal_file_number'] = $messages;
            }
        }

        return $errors;
    }
    protected function virtualFields(): array { return ['group_name','specialty','auto_account','education_document_type','education_document_series','education_document_number','education_document_issue_date','education_document_organization','education_graduation_year']; }
    private function studentStatus(?string $value): string { return match(mb_strtolower(trim((string)$value))) { 'академический отпуск','academic_leave'=>'academic_leave','выпускник','graduated'=>'graduated','отчислен','expelled'=>'expelled', default=>'active' }; }

    /**
     * Паспорт и документ об образовании принимаются, но не требуются: строка без них —
     * не ошибка, студент просто получает неполную карточку. Документы кладутся человеку,
     * поэтому импорт заодно связывает студента с личной карточкой.
     *
     * @param array<string, mixed> $data
     */
    private function syncDocuments(Student $student, array $data): void
    {
        $person = $this->people->ensureForStudent($student)['person'];

        $this->identityDocuments->syncPassportForPerson($person->id, [
            'series' => $data['passport_series'] ?? null,
            'number' => $data['passport_number'] ?? null,
            'issue_date' => $data['passport_issue_date'] ?? null,
            'issued_by' => $data['passport_issued_by'] ?? null,
        ]);

        $this->educationDocuments->syncForPerson($person->id, [
            'document_type' => $data['education_document_type'] ?? null,
            'series' => $data['education_document_series'] ?? null,
            'number' => $data['education_document_number'] ?? null,
            'issue_date' => $data['education_document_issue_date'] ?? null,
            'document_organization' => $data['education_document_organization'] ?? null,
            'graduation_year' => $data['education_graduation_year'] ?? null,
        ]);
    }
}
