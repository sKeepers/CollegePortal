<?php
namespace App\Services\Import;
use App\Models\Subject; use App\Services\AutoCodeService; use Illuminate\Database\Eloquent\Model;
class SubjectImportHandler extends AbstractImportHandler { public function __construct(private readonly AutoCodeService $autoCodeService) {}
 public function type(): string { return 'subjects'; } public function label(): string { return 'Дисциплины'; } public function modelClass(): string { return Subject::class; } public function keyFields(): array { return ['code']; }
 public function fields(): array { return ['name'=>['label'=>'Название','required'=>true,'aliases'=>['дисциплина','название','name']],'code'=>['label'=>'Код','required'=>false,'aliases'=>['код','code']],'department'=>['label'=>'Отделение','required'=>false,'aliases'=>['отделение','кафедра','department']],'description'=>['label'=>'Описание','required'=>false,'aliases'=>['описание','description']]]; }
 public function templateHeaders(): array { return ['Дисциплина','Код','Отделение','Описание']; } public function templateExample(): array { return ['История музыки','MUS-101','Музыкальное отделение','Базовая дисциплина']; }
 public function prepare(array $data): array { $data['code']=$data['code'] ?: $this->autoCodeService->subjectCode($data['name']??null); return $data; }
 public function rules(): array { return ['name'=>['required','string','max:255'],'code'=>['nullable','string','max:100'],'department'=>['nullable','string','max:255'],'description'=>['nullable','string']]; }
 public function findExisting(array $data): ?Model { return !empty($data['code']) ? Subject::where('code',$data['code'])->first() : Subject::where('name',$data['name']??null)->first(); }
}
