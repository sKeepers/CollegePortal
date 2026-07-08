<?php
namespace App\Services\Import;
use App\Models\Group; use App\Services\Import\Concerns\ResolvesImportRelations; use Illuminate\Database\Eloquent\Model;
class GroupImportHandler extends AbstractImportHandler { use ResolvesImportRelations;
 public function type(): string { return 'groups'; } public function label(): string { return 'Группы'; } public function modelClass(): string { return Group::class; } public function keyFields(): array { return ['name']; }
 public function fields(): array { return ['name'=>['label'=>'Название','required'=>true,'aliases'=>['группа','название','name']],'specialty'=>['label'=>'Специальность','required'=>true,'aliases'=>['специальность','specialty']],'course'=>['label'=>'Курс','required'=>true,'aliases'=>['курс','course']],'year_start'=>['label'=>'Год набора','required'=>true,'aliases'=>['год набора','year_start']],'education_program_id'=>['label'=>'ID программы','required'=>false,'aliases'=>['education_program_id']],'education_program_name'=>['label'=>'Программа','required'=>false,'aliases'=>['программа','образовательная программа']]]; }
 public function templateHeaders(): array { return ['Группа','Специальность','Курс','Год набора','Образовательная программа']; } public function templateExample(): array { return ['ИСП-101','Инструментальное исполнительство','1','2026','Фортепиано']; }
 public function prepare(array $data): array { $data['education_program_id']=$this->resolveProgramId($data['education_program_id']??null,$data['education_program_name']??null); return $data; }
 public function rules(): array { return ['name'=>['required','string','max:255'],'specialty'=>['required','string','max:255'],'education_program_id'=>['nullable','integer','exists:education_programs,id'],'course'=>['required','integer','min:1','max:6'],'year_start'=>['required','integer','min:2000','max:2100']]; }
 public function findExisting(array $data): ?Model { return Group::where('name',$data['name']??null)->first(); } protected function virtualFields(): array { return ['education_program_name']; }
}
