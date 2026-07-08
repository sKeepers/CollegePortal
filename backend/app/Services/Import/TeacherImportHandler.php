<?php
namespace App\Services\Import;
use App\Models\Teacher; use Illuminate\Database\Eloquent\Model;
class TeacherImportHandler extends AbstractImportHandler {
 public function type(): string { return 'teachers'; } public function label(): string { return 'Преподаватели'; } public function modelClass(): string { return Teacher::class; } public function keyFields(): array { return ['email']; }
 public function fields(): array { return ['last_name'=>['label'=>'Фамилия','required'=>true,'aliases'=>['фамилия','last_name']],'first_name'=>['label'=>'Имя','required'=>true,'aliases'=>['имя','first_name']],'middle_name'=>['label'=>'Отчество','required'=>false,'aliases'=>['отчество','middle_name']],'phone'=>['label'=>'Телефон','required'=>false,'aliases'=>['телефон','phone']],'email'=>['label'=>'Email','required'=>false,'aliases'=>['email','почта','e-mail']],'position'=>['label'=>'Должность','required'=>false,'aliases'=>['должность','position']],'department'=>['label'=>'Отделение','required'=>false,'aliases'=>['отделение','кафедра','department']],'is_active'=>['label'=>'Активен','required'=>false,'aliases'=>['активен','is_active']]]; }
 public function templateHeaders(): array { return ['Фамилия','Имя','Отчество','Телефон','Email','Должность','Отделение','Активен']; } public function templateExample(): array { return ['Петрова','Анна','Викторовна','+79990000010','teacher@example.test','Преподаватель','Музыкальное отделение','да']; }
 public function prepare(array $data): array { $data['is_active']=$this->booleanValue($data['is_active']??true); return $data; }
 public function rules(): array { return ['last_name'=>['required','string','max:255'],'first_name'=>['required','string','max:255'],'middle_name'=>['nullable','string','max:255'],'phone'=>['nullable','string','max:50'],'email'=>['nullable','email','max:255'],'position'=>['nullable','string','max:255'],'department'=>['nullable','string','max:255'],'is_active'=>['boolean']]; }
 public function findExisting(array $data): ?Model { return !empty($data['email']) ? Teacher::where('email',$data['email'])->first() : null; }
}
