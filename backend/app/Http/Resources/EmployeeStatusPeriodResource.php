<?php
namespace App\Http\Resources;
use Illuminate\Http\Request; use Illuminate\Http\Resources\Json\JsonResource;
class EmployeeStatusPeriodResource extends JsonResource { public function toArray(Request $request): array { return ['id'=>$this->id,'employee_id'=>$this->employee_id,'status'=>$this->status,'date_from'=>$this->date_from?->toDateString(),'date_to'=>$this->date_to?->toDateString(),'reason'=>$this->reason,'document_number'=>$this->document_number,'document_date'=>$this->document_date?->toDateString(),'comment'=>$this->comment,'created_by'=>$this->created_by]; } }
