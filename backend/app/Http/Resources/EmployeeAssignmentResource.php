<?php
namespace App\Http\Resources;
use Illuminate\Http\Request; use Illuminate\Http\Resources\Json\JsonResource;
class EmployeeAssignmentResource extends JsonResource { public function toArray(Request $request): array { return ['id'=>$this->id,'employee_id'=>$this->employee_id,'department_id'=>$this->department_id,'position_id'=>$this->position_id,'employment_type'=>$this->employment_type,'rate'=>$this->rate,'started_at'=>$this->started_at?->toDateString(),'ended_at'=>$this->ended_at?->toDateString(),'is_primary'=>(bool)$this->is_primary,'order_number'=>$this->order_number,'order_date'=>$this->order_date?->toDateString(),'comment'=>$this->comment,'department'=>new DepartmentResource($this->whenLoaded('department')),'position'=>new PositionResource($this->whenLoaded('position'))]; } }
