<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Какие две карточки сливаются.
 *
 * `survivor` — та, что остаётся и на которую всё переезжает; `absorbed` — та,
 * что исчезает. Названы явно, а не «первая» и «вторая»: перепутать их местами
 * значит потерять не ту карточку, а обратного хода у слияния нет.
 */
class MergePeopleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'survivor_id' => ['required', 'integer', 'exists:people,id'],
            'absorbed_id' => ['required', 'integer', 'exists:people,id', 'different:survivor_id'],
        ];
    }

    public function messages(): array
    {
        return [
            'survivor_id.required' => 'Не указана карточка, которая остаётся.',
            'survivor_id.exists' => 'Карточка, которая остаётся, не найдена.',
            'absorbed_id.required' => 'Не указана карточка, которая присоединяется.',
            'absorbed_id.exists' => 'Присоединяемая карточка не найдена.',
            'absorbed_id.different' => 'Это одна и та же карточка.',
        ];
    }
}
