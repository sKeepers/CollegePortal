<?php
namespace App\Services\Import;
use App\Models\Classroom; use Illuminate\Database\Eloquent\Model;
class ClassroomImportHandler extends AbstractImportHandler {
 public function type(): string { return 'classrooms'; } public function label(): string { return 'Аудитории'; } public function modelClass(): string { return Classroom::class; } public function keyFields(): array { return ['number','building']; }
 public function fields(): array { return ['number'=>['label'=>'Номер','required'=>true,'aliases'=>['номер','аудитория','number']],'building'=>['label'=>'Корпус','required'=>false,'aliases'=>['корпус','building']],'floor'=>['label'=>'Этаж','required'=>false,'aliases'=>['этаж','floor']],'capacity'=>['label'=>'Вместимость','required'=>false,'aliases'=>['вместимость','capacity']],'type'=>['label'=>'Тип','required'=>false,'aliases'=>['тип','type']],'description'=>['label'=>'Описание','required'=>false,'aliases'=>['описание','description']]]; }
 public function templateHeaders(): array { return ['Аудитория','Корпус','Этаж','Вместимость','Тип','Описание']; } public function templateExample(): array { return ['201','Главный корпус','2','24','Учебная аудитория','Фортепианный класс']; }
 /**
  * Пустая клетка значит «нет данных», а не пустую строку.
  *
  * Загрузчик её не приводил вовсе, и пустая «Вместимость» уходила в числовую
  * колонку как есть: PostgreSQL отвечал `SQLSTATE[22P02] invalid input syntax
  * for type smallint`, и отказывала **вся строка**. 28.08.2026 на этом легла
  * первая же проба списка кабинетов — десять строк из десяти, при том что
  * вместимости владелец не называл и называть не был должен.
  *
  * Отказ при этом выглядел поломкой портала, а не ошибкой в файле: человек
  * видит имя типа PostgreSQL и не понимает, что чинить.
  */
 public function prepare(array $data): array { foreach (array_keys($this->fields()) as $field) { if (($data[$field] ?? null) === '') { $data[$field] = null; } } return $data; }
 public function rules(): array { return ['number'=>['required','string','max:50'],'building'=>['nullable','string','max:255'],'floor'=>['nullable','integer','min:0','max:50'],'capacity'=>['nullable','integer','min:1','max:1000'],'type'=>['nullable','string','max:255'],'description'=>['nullable','string']]; }
 public function findExisting(array $data): ?Model { return Classroom::where('number',$data['number']??null)->where('building',$data['building']??null)->first(); }
}
