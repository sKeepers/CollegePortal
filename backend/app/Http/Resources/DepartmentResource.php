<?php
namespace App\Http\Resources;
use Illuminate\Http\Request; use Illuminate\Http\Resources\Json\JsonResource;
class DepartmentResource extends JsonResource { public function toArray(Request $request): array { return ['id'=>$this->id,'code'=>$this->code,'name'=>$this->name,'parent_id'=>$this->parent_id,'type'=>$this->type,'head_employee_id'=>$this->head_employee_id,'is_active'=>(bool)$this->is_active,'parent'=>new DepartmentResource($this->whenLoaded('parent')),'created_at'=>$this->created_at?->toISOString(),'updated_at'=>$this->updated_at?->toISOString()]; } }
