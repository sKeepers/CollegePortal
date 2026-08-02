<?php

namespace App\Services\Import;

use App\Models\Person;
use App\Models\Student;
use App\Services\Admissions\SnilsService;
use App\Services\Import\Concerns\ResolvesImportRelations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StudentImportHandler extends AbstractImportHandler
{
    use ResolvesImportRelations;

    public function __construct(private readonly SnilsService $snils) {}

    public function type(): string { return 'students'; }
    public function label(): string { return 'Студенты'; }
    public function modelClass(): string { return Student::class; }
    public function keyFields(): array { return ['email']; }
    public function fields(): array { return ['last_name'=>['label'=>'Фамилия','required'=>true,'aliases'=>['фамилия','last_name']],'first_name'=>['label'=>'Имя','required'=>true,'aliases'=>['имя','first_name']],'middle_name'=>['label'=>'Отчество','required'=>false,'aliases'=>['отчество','middle_name']],'group_id'=>['label'=>'ID группы','required'=>false,'aliases'=>['group_id','id группы']],'group_name'=>['label'=>'Группа','required'=>false,'aliases'=>['группа','group','group_name']],'birth_date'=>['label'=>'Дата рождения','required'=>false,'aliases'=>['дата рождения','birth_date']],'phone'=>['label'=>'Телефон','required'=>false,'aliases'=>['телефон','phone']],'email'=>['label'=>'Email','required'=>false,'aliases'=>['email','почта','e-mail']],'snils'=>['label'=>'СНИЛС','required'=>true,'aliases'=>['снилс','snils']],'status'=>['label'=>'Статус','required'=>false,'aliases'=>['статус','status']],'enrollment_date'=>['label'=>'Дата зачисления','required'=>false,'aliases'=>['дата зачисления','enrollment_date']]]; }
    public function templateHeaders(): array { return ['Фамилия','Имя','Отчество','Группа','Дата рождения','Телефон','Email','СНИЛС','Статус','Дата зачисления']; }
    public function templateExample(): array { return ['Иванов','Дмитрий','Сергеевич','ИСП-101','12.05.2009','+79990000002','student@example.test','112-233-445 95','active','01.09.2026']; }
    public function prepare(array $data): array { $data['birth_date']=$this->normalizeDate($data['birth_date']??null); $data['enrollment_date']=$this->normalizeDate($data['enrollment_date']??null); $data['group_id']=$this->resolveGroupId($data['group_id']??null,$data['group_name']??null); $data['status']=$this->studentStatus($data['status']??null); return $data; }
    public function rules(): array { return ['group_id'=>['required','integer','exists:groups,id'],'last_name'=>['required','string','max:255'],'first_name'=>['required','string','max:255'],'middle_name'=>['nullable','string','max:255'],'birth_date'=>['nullable','date'],'phone'=>['nullable','string','max:50'],'email'=>['nullable','email','max:255'],'snils'=>['nullable','string','max:32'],'status'=>['required','in:active,academic_leave,graduated,expelled'],'enrollment_date'=>['nullable','date']]; }
    public function findExisting(array $data): ?Model { return !empty($data['email']) ? Student::where('email',$data['email'])->first() : null; }
    public function businessValidationErrors(array $data): array
    {
        if ($this->findExisting($data) && blank($data['snils'] ?? null)) { return []; }
        if (blank($data['snils'] ?? null)) { return ['snils' => ['Для нового студента укажите СНИЛС.']]; }
        try { $this->snils->normalize($data['snils']); } catch (ValidationException $e) { return ['snils' => $e->errors()['snils'] ?? [$e->getMessage()]]; }
        return [];
    }
    public function import(array $data, string $mode): string
    {
        $existing = $this->findExisting($data);
        if ($mode === self::MODE_UPDATE) { if (!$existing) return 'skipped'; unset($data['snils']); $existing->update($this->payload($data, true)); return 'updated'; }
        if ($existing) { if ($mode === self::MODE_SKIP_DUPLICATES) return 'skipped'; throw new RuntimeException('Дубликат по ключевому полю.'); }
        $normalized = $this->snils->normalize($data['snils'] ?? null); $hash = $this->snils->hash($normalized);
        return DB::transaction(function () use ($data, $normalized, $hash): string {
            $person = Person::query()->firstOrCreate(['snils_hash' => $hash], ['last_name'=>$data['last_name'],'first_name'=>$data['first_name'],'middle_name'=>$data['middle_name']??null,'birth_date'=>$data['birth_date']??null,'phone'=>$data['phone']??null,'email'=>$data['email']??null,'snils'=>$normalized,'snils_hash'=>$hash,'status'=>'active']);
            unset($data['snils']); Student::create([...$this->payload($data), 'person_id' => $person->id]); return 'created';
        });
    }
    protected function virtualFields(): array { return ['group_name']; }
    private function studentStatus(?string $value): string { return match(mb_strtolower(trim((string)$value))) { 'академический отпуск','academic_leave'=>'academic_leave','выпускник','graduated'=>'graduated','отчислен','expelled'=>'expelled', default=>'active' }; }
}
