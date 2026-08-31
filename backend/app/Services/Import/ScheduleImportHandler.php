<?php
namespace App\Services\Import;
use App\DTO\ScheduleLessonData; use App\Models\ScheduleLesson; use App\Models\User; use App\Services\Import\Concerns\ResolvesImportRelations; use App\Services\ScheduleEngineService; use App\Services\ScheduleLessonService; use Illuminate\Database\Eloquent\Model; use RuntimeException;
class ScheduleImportHandler extends AbstractImportHandler { use ResolvesImportRelations; public function __construct(private readonly ScheduleLessonService $scheduleLessonService, private readonly ScheduleEngineService $engine) {}
 public function type(): string { return 'schedule'; } public function label(): string { return 'Расписание'; } public function modelClass(): string { return ScheduleLesson::class; } public function keyFields(): array { return ['lesson_date','starts_at','group_id','subject_id','teacher_id']; }
 public function fields(): array { return ['lesson_date'=>['label'=>'Дата','required'=>true,'aliases'=>['дата','lesson_date']],'starts_at'=>['label'=>'Время начала','required'=>true,'aliases'=>['время начала','starts_at','начало']],'ends_at'=>['label'=>'Время окончания','required'=>true,'aliases'=>['время окончания','ends_at','окончание']],'group_id'=>['label'=>'ID группы','required'=>false,'aliases'=>['group_id','id группы']],'group_name'=>['label'=>'Группа','required'=>false,'aliases'=>['группа','group','group_name']],'teacher_id'=>['label'=>'ID преподавателя','required'=>false,'aliases'=>['teacher_id','id преподавателя']],'teacher_name'=>['label'=>'Преподаватель','required'=>false,'aliases'=>['преподаватель','teacher','teacher_name']],'subject_id'=>['label'=>'ID дисциплины','required'=>false,'aliases'=>['subject_id','id дисциплины']],'subject_code'=>['label'=>'Код дисциплины','required'=>false,'aliases'=>['код дисциплины','subject_code']],'subject_name'=>['label'=>'Дисциплина','required'=>false,'aliases'=>['дисциплина','subject_name']],'classroom_id'=>['label'=>'ID аудитории','required'=>false,'aliases'=>['classroom_id','id аудитории']],'classroom_number'=>['label'=>'Аудитория','required'=>false,'aliases'=>['аудитория','classroom','classroom_number']],'classroom_building'=>['label'=>'Корпус','required'=>false,'aliases'=>['корпус','building','classroom_building']],'lesson_type'=>['label'=>'Тип занятия','required'=>false,'aliases'=>['тип занятия','lesson_type']],'topic'=>['label'=>'Тема','required'=>false,'aliases'=>['тема','topic']]]; }

 /**
  * Аудитория названа, но такой в портале нет.
  *
  * До 24.08.2026 это проходило молча: `resolveClassroomId` возвращал `null`, правило
  * стояло `nullable`, строка загружалась, и занятие появлялось **без кабинета вовсе**.
  * Опечатка в номере ничем не отличалась от намеренно пустой клетки — а первого сентября
  * это группа в коридоре и никто не знает почему. Замерено на репетиции: файл с
  * аудиторией «999» дал ноль ошибок и занятие без аудитории.
  *
  * Сообщение написано прямо в замыкании намеренно. Каталога переводов `lang/` в проекте
  * нет, поэтому обычные правила отвечают ключами вида `validation.exists`; замыкание
  * возвращает свой текст и в переводе не нуждается. `bail` рядом — чтобы к нему не
  * добавилось ещё и `validation.exists` от следующего правила.
  */
 private const CLASSROOM_NOT_FOUND = -1;

 private function classroomWasResolved(): callable
 {
     return static function (string $attribute, $value, callable $fail): void {
         if ((int) $value === self::CLASSROOM_NOT_FOUND) {
             $fail('Аудитория из файла в портале не найдена. Проверьте номер и корпус или заведите аудиторию заранее.');
         }

         // Спор о корпусе — отдельный случай и отдельное сообщение. «Не найдена»
         // здесь было бы неправдой: аудитория как раз найдена, и не одна, а
         // человек пошёл бы искать опечатку в номере, которой нет.
         if ((int) $value === self::CLASSROOM_AMBIGUOUS) {
             $fail('Аудитория с таким номером есть в нескольких корпусах. Укажите колонку «Корпус» или ID аудитории.');
         }
     };
 }

 public function templateHeaders(): array { return ['Дата','Время начала','Время окончания','Группа','Преподаватель','Дисциплина','Код дисциплины','Аудитория','Корпус','Тип занятия','Тема']; } public function templateExample(): array { return ['01.09.2026','09:00','10:30','ИСП-101','Петрова Анна Викторовна','Специальность','SPEC-001','201','Главный корпус','Практическое','Вводное занятие']; }
 public function prepare(array $data): array { $data['lesson_date']=$this->normalizeDate($data['lesson_date']??null); $data['starts_at']=$this->normalizeTime($data['starts_at']??null); $data['ends_at']=$this->normalizeTime($data['ends_at']??null); $data['group_id']=$this->resolveGroupId($data['group_id']??null,$data['group_name']??null); $data['teacher_id']=$this->resolveTeacherId($data['teacher_id']??null,$data['teacher_name']??null); $data['subject_id']=$this->resolveSubjectId($data['subject_id']??null,$data['subject_code']??null,$data['subject_name']??null); $data['classroom_id']=$this->resolveClassroomId($data['classroom_id']??null,$data['classroom_number']??null,$data['classroom_building']??null); $data['classroom_id']=$data['classroom_id'] ?? (filled($data['classroom_number']??null) ? self::CLASSROOM_NOT_FOUND : null); $data['lesson_type']=$data['lesson_type'] ?: 'lesson'; return $data; }
 public function rules(): array { return ['lesson_date'=>['required','date'],'starts_at'=>['required','date_format:H:i'],'ends_at'=>['required','date_format:H:i','after:starts_at'],'group_id'=>['required','integer','exists:groups,id'],'teacher_id'=>['required','integer','exists:teachers,id'],'subject_id'=>['required','integer','exists:subjects,id'],'classroom_id'=>['nullable','bail','integer',$this->classroomWasResolved(),'exists:classrooms,id'],'lesson_type'=>['required','string','max:255'],'topic'=>['nullable','string','max:255']]; }
 /**
  * Дата сравнивается через whereDate, а не строкой. Записи, пришедшие зеркалом
  * от движка расписания, лежат с нулевым временем — `2026-09-01 00:00:00`, — и
  * сравнение со строкой `2026-09-01` их не находило: повторный импорт того же
  * файла заводил вторые занятия вместо обновления. Соседний
  * `scheduleHasConflict` тем же полем пользуется правильно.
  */
 public function findExisting(array $data): ?Model { return ScheduleLesson::whereDate('lesson_date',$data['lesson_date']??null)->where('starts_at',$data['starts_at']??null)->where('group_id',$data['group_id']??null)->where('subject_id',$data['subject_id']??null)->where('teacher_id',$data['teacher_id']??null)->first(); }
 /**
  * Занятие заводится движком расписания, а legacy-запись появляется его
  * зеркалом. До 10.08.2026 импорт создавал только legacy-запись, а покрытие
  * часов нагрузки считается по `ScheduleEntry` — заведующий загружал расписание
  * семестра файлом и видел «запланировано 0 часов из 72», будто расписания нет.
  *
  * Движок отказывается заводить занятие, если дисциплины нет в нагрузке группы.
  * Поэтому там, где строка нагрузки не находится, импорт по-прежнему пишет
  * legacy-запись напрямую: иначе файлы, которые грузились вчера, сегодня начали
  * бы падать целиком. Такая строка в покрытие не попадёт — но она и раньше
  * туда не попадала.
  */
 public function import(array $data,string $mode): string { $lesson=$this->findExisting($data); if($reason=$this->groupBusySkipReason($data,$lesson?->id)){return $this->skipped($reason);} if($mode===self::MODE_UPDATE && !$lesson){return $this->skipped(self::SKIP_NOT_FOUND);} if($lesson){ if($mode===self::MODE_SKIP_DUPLICATES){return $this->skipped(self::SKIP_DUPLICATE);} if($mode===self::MODE_CREATE){throw new RuntimeException('Занятие уже существует.');}}
     $loadItem=$this->engine->loadItemFor($this->enginePayload($data));
     if($lesson){ if($lesson->schedule_entry_id && ($entry=$lesson->scheduleEntry)){ $this->engine->update($entry,$this->enginePayload($data,$loadItem?->id),$this->currentUser()); return 'updated'; } $this->scheduleLessonService->update($lesson,ScheduleLessonData::fromArray($this->schedulePayload($data))); return 'updated'; }
     if($loadItem){ $this->engine->apply($this->enginePayload($data,$loadItem->id),$this->currentUser()); return 'created'; }
     $this->scheduleLessonService->create(ScheduleLessonData::fromArray($this->schedulePayload($data))); return 'created'; }
 private function currentUser(): ?User { $user=auth()->user(); return $user instanceof User ? $user : null; }
 /**
  * Поля движка: у него свои имена и свой набор — дата вместо lesson_date,
  * комментарий вместо темы.
  *
  * Строка нагрузки проставляется явно. Движок находит её сам, но только чтобы
  * проверить занятие, а в запись не пишет; покрытие же считает по записям
  * с заполненным `teaching_load_item_id`. Без этого поля занятие создавалось,
  * а на экране покрытия по-прежнему стояли нули — то есть находка осталась бы
  * незакрытой при переписанном импорте.
  */
 private function enginePayload(array $data,?int $loadItemId=null): array { return ['date'=>$data['lesson_date'],'starts_at'=>$data['starts_at'],'ends_at'=>$data['ends_at'],'group_id'=>$data['group_id'],'subject_id'=>$data['subject_id'],'teacher_id'=>$data['teacher_id'],'classroom_id'=>$data['classroom_id']??null,'teaching_load_item_id'=>$loadItemId,'comment'=>$data['topic']??null,'source'=>'import']; }
 public function businessValidationErrors(array $data): array { return $this->scheduleConflictMessages($data,$this->findExisting($data)?->id); }
 private function schedulePayload(array $data): array { return ['group_id'=>$data['group_id'],'teacher_id'=>$data['teacher_id'],'subject_id'=>$data['subject_id'],'classroom_id'=>$data['classroom_id']??null,'lesson_date'=>$data['lesson_date'],'starts_at'=>$data['starts_at'],'ends_at'=>$data['ends_at'],'lesson_type'=>$data['lesson_type'] ?: 'lesson','topic'=>$data['topic']??null]; }
 /**
  * Занятость группы: отказ строке заменён пропуском с названной причиной.
  *
  * Владелец подтвердил 01.09.2026, что в расписании есть подгруппы — две пары одной
  * группы в одно время у разных преподавателей — и индивидуальные занятия у отдельных
  * студентов. Портал такого не заводит: занятость группы блокирующая в четырёх местах,
  * а признака подгруппы в модели нет вовсе (разбор —
  * `docs/SCHEDULE_SUBGROUPS_AND_INDIVIDUAL_LESSONS.md`).
  *
  * Пока признака нет, у файла с подгруппами два исхода. Прежний: половина строк
  * отказывает, и расписание встаёт наполовину, а оператор видит стену ошибок про
  * «занята» — про занятия, которых он не ставил. Нынешний: первая пара клетки встаёт,
  * остальные **пропускаются с перечислением**, и завуч видит поимённо, чему не нашлось
  * места. Ничего не теряется молча, и настоящая накладка по-прежнему ловится: аудитория
  * и преподаватель остаются ошибкой, потому что они не раздваиваются, а группа — делится.
  *
  * Заводить подгруппу нумерацией по порядку строк нельзя: порядок в следующей выгрузке
  * поменяется, и журнал поедет за не той парой.
  */
 private function groupBusySkipReason(array $data,?int $ignoreLessonId): ?string
 {
     $busy=ScheduleLesson::query()->with(['subject','teacher','classroom'])->where('group_id',(int)$data['group_id'])
         ->whereDate('lesson_date',$data['lesson_date'])->where('starts_at','<',$data['ends_at'])->where('ends_at','>',$data['starts_at'])
         ->when($ignoreLessonId,fn($query)=>$query->whereKeyNot($ignoreLessonId))->first();

     if(!$busy){ return null; }

     $stands=array_filter([$busy->subject?->name,$busy->teacher?->last_name,$busy->classroom?->number?'ауд. '.$busy->classroom->number:null]);

     return 'В это время у группы уже стоит занятие: '.implode(', ',$stands).'. Портал пока не умеет ставить одной группе две пары в одно время: подгруппы и индивидуальные занятия он различать не научен, и эта строка не загружена. Если это подгруппа — она ждёт признака подгруппы в портале; если накладка — её надо развести в файле.';
 }
 private function scheduleConflictMessages(array $data,?int $ignoreLessonId=null): array { $errors=[];  if($this->scheduleHasConflict('teacher_id',(int)$data['teacher_id'],$data,$ignoreLessonId)){$errors['teacher_id'][]='Преподаватель уже ведет занятие в это время.';} if(!empty($data['classroom_id']) && $this->scheduleHasConflict('classroom_id',(int)$data['classroom_id'],$data,$ignoreLessonId)){$errors['classroom_id'][]='Аудитория уже занята в это время.';} return $errors; }
 private function scheduleHasConflict(string $column,int $value,array $data,?int $ignoreLessonId): bool { return ScheduleLesson::query()->where($column,$value)->whereDate('lesson_date',$data['lesson_date'])->where('starts_at','<',$data['ends_at'])->where('ends_at','>',$data['starts_at'])->when($ignoreLessonId,fn($query)=>$query->whereKeyNot($ignoreLessonId))->exists(); }
}
