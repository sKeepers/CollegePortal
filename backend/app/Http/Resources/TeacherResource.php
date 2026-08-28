<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class TeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'person_id' => $this->person_id,
            // Карты человека — под правом на их просмотр, а не всем, кто видит
            // карточку. Карта открывает дверь, и круг видящих её не должен
            // расширяться заодно с круглом видящих телефон.
            //
            // Карт бывает несколько: у четверых людей на 29.08.2026 их две-три,
            // и одно поле «номер карты» соврало бы на первой же такой карточке.
            // Состояние показывается у каждой: у кого карту забрали, не должен
            // видеть её действующей.
            'rfid_cards' => $this->when(
                (bool) $request->user()?->hasPermission('rfid.cards.view'),
                fn () => RfidCardResource::collection(
                    $this->person?->rfidCards->sortByDesc('issued_at')->values() ?? collect(),
                )->resolve(),
            ),

            'user_id' => $this->user_id,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'photo_path' => $this->photo_path,
            'photo_url' => $this->photo_path ? $request->getSchemeAndHttpHost().Storage::disk('public')->url($this->photo_path) : null,
            'position' => $this->position,
            'department' => $this->department,
            'is_active' => $this->is_active,
        ];
    }
}
