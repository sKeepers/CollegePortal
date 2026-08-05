<?php

namespace App\Services\Import;

use App\Models\Teacher;
use App\Services\AccountProvisioningService;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class TeacherImportHandler extends AbstractImportHandler
{
    public function __construct(private readonly AccountProvisioningService $accounts)
    {
    }

    public function type(): string { return 'teachers'; }
    public function label(): string { return 'Преподаватели'; }
    public function modelClass(): string { return Teacher::class; }
    public function keyFields(): array { return ['email']; }
    public function fields(): array { return ['last_name'=>['label'=>'Фамилия','required'=>true,'aliases'=>['фамилия','last_name']],'first_name'=>['label'=>'Имя','required'=>true,'aliases'=>['имя','first_name']],'middle_name'=>['label'=>'Отчество','required'=>false,'aliases'=>['отчество','middle_name']],'phone'=>['label'=>'Телефон','required'=>false,'aliases'=>['телефон','phone']],'email'=>['label'=>'Email','required'=>false,'aliases'=>['email','почта','e-mail']],'position'=>['label'=>'Должность','required'=>false,'aliases'=>['должность','position']],'department'=>['label'=>'Отделение','required'=>false,'aliases'=>['отделение','кафедра','department']],'is_active'=>['label'=>'Активен','required'=>false,'aliases'=>['активен','is_active']],'auto_account'=>['label'=>'Создать учетную запись','required'=>false,'aliases'=>['создать учетную запись','auto_account']]]; }
    public function templateHeaders(): array { return ['Фамилия','Имя','Отчество','Телефон','Email','Должность','Отделение','Активен','Создать учетную запись']; }
    public function templateExample(): array { return ['Петрова','Анна','Викторовна','+79990000010','teacher@example.test','Преподаватель','Музыкальное отделение','да','нет']; }
    public function prepare(array $data): array { $data['is_active']=$this->booleanValue($data['is_active']??true); $data['auto_account']=$this->booleanValue($data['auto_account']??false); return $data; }
    public function rules(): array { return ['last_name'=>['required','string','max:255'],'first_name'=>['required','string','max:255'],'middle_name'=>['nullable','string','max:255'],'phone'=>['nullable','string','max:50'],'email'=>['nullable','email','max:255'],'position'=>['nullable','string','max:255'],'department'=>['nullable','string','max:255'],'is_active'=>['boolean'],'auto_account'=>['boolean']]; }
    public function findExisting(array $data): ?Model { return !empty($data['email']) ? Teacher::where('email',$data['email'])->first() : null; }
    public function import(array $data, string $mode): string { $existing=$this->findExisting($data); if ($mode===self::MODE_UPDATE) { if (!$existing) return 'skipped'; $existing->update($this->payload($data,true)); return 'updated'; } if ($existing) { if ($mode===self::MODE_SKIP_DUPLICATES) return 'skipped'; throw new RuntimeException('Дубликат по ключевому полю.'); } $teacher=Teacher::create($this->payload($data)); if ($data['auto_account']) $this->accounts->provision($teacher); return 'created'; }
    protected function virtualFields(): array { return ['auto_account']; }
}
