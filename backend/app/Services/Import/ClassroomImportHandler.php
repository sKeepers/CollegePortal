<?php
namespace App\Services\Import;
use App\Models\Classroom; use Illuminate\Database\Eloquent\Model;
class ClassroomImportHandler extends AbstractImportHandler {
 public function type(): string { return 'classrooms'; } public function label(): string { return 'Аудитории'; } public function modelClass(): string { return Classroom::class; } public function keyFields(): array { return ['number','building']; }
 public function fields(): array { return ['number'=>['label'=>'Номер','required'=>true,'aliases'=>['номер','аудитория','number']],'building'=>['label'=>'Корпус','required'=>false,'aliases'=>['корпус','building']],'floor'=>['label'=>'Этаж','required'=>false,'aliases'=>['этаж','floor']],'capacity'=>['label'=>'Вместимость','required'=>false,'aliases'=>['вместимость','capacity']],'type'=>['label'=>'Тип','required'=>false,'aliases'=>['тип','type']],'description'=>['label'=>'Описание','required'=>false,'aliases'=>['описание','description']]]; }
 public function templateHeaders(): array { return ['Аудитория','Корпус','Этаж','Вместимость','Тип','Описание']; } public function templateExample(): array { return ['201','Главный корпус','2','24','Учебная аудитория','Фортепианный класс']; }
 public function rules(): array { return ['number'=>['required','string','max:50'],'building'=>['nullable','string','max:255'],'floor'=>['nullable','integer','min:0','max:50'],'capacity'=>['nullable','integer','min:1','max:1000'],'type'=>['nullable','string','max:255'],'description'=>['nullable','string']]; }
 public function findExisting(array $data): ?Model { return Classroom::where('number',$data['number']??null)->where('building',$data['building']??null)->first(); }
}
