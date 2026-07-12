<?php
namespace App\Http\Resources;
use Illuminate\Http\Request; use Illuminate\Http\Resources\Json\JsonResource;
class PositionResource extends JsonResource { public function toArray(Request $request): array { return ['id'=>$this->id,'code'=>$this->code,'name'=>$this->name,'category'=>$this->category,'is_teaching_position'=>(bool)$this->is_teaching_position,'is_active'=>(bool)$this->is_active,'created_at'=>$this->created_at?->toISOString(),'updated_at'=>$this->updated_at?->toISOString()]; } }
