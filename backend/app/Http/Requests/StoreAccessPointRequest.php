<?php

namespace App\Http\Requests;

use App\Models\AccessPoint;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessPointRequest extends FormRequest
{
    public function rules(): array
    {
        $accessPoint = $this->route('access_point');

        return [
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('access_points', 'name')
                    ->where(fn ($query) => $query->where('building_id', $this->input('building_id')))
                    ->ignore($accessPoint),
            ],
            'code' => ['nullable', 'string', 'max:32', Rule::unique('access_points', 'code')->ignore($accessPoint)],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * Название и адрес правит любой, у кого есть право на справочник, а код —
     * только администратор. Решение владельца от 11.08.2026, и причина не в
     * секретности: код связывает запись с железом. Его набирают в сканере при
     * установке, и опечатка означает, что проходная перестаёт узнавать точку —
     * события продолжают приходить, но ложатся «вне справочника», а отчёт «Кто в
     * здании» тихо пустеет.
     *
     * Проверка стоит здесь, а не только на экране: спрятанное поле формы
     * обходится одним запросом, а право должно держаться на сервере.
     *
     * Проверять приходится **после** правил, а не правилом в их ряду: рядом с
     * `nullable` замыкание не вызывается вовсе, когда значение пустое, — и
     * очистка чужого кода прошла бы молча. Здесь пустое значение такая же
     * правка, как любая другая.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Поля нет в запросе — правки нет: `update` его не тронет.
            if ($this->userMayEditCode() || ! $this->has('code')) {
                return;
            }

            $current = $this->currentCode();

            if ($this->normalizeCode($this->input('code')) === $this->normalizeCode($current)) {
                return;
            }

            $validator->errors()->add('code', $current === null
                ? 'Код точки прохода задаёт только администратор: он связывает запись со сканером на проходной. Сохраните точку без кода — код проставит администратор.'
                : 'Код точки прохода меняет только администратор: он связывает запись со сканером на проходной.');
        });
    }

    /**
     * Код, совпадающий с сохранённым с точностью до регистра и пробелов, — это
     * не правка, а форма, которая вернула запись целиком. Такой приводится к
     * сохранённому виду до проверки: иначе не администратор смог бы переписать
     * `GOL21` на `gol21`, формально ничего не меняя.
     */
    protected function prepareForValidation(): void
    {
        if ($this->userMayEditCode() || ! $this->has('code')) {
            return;
        }

        $current = $this->currentCode();

        if ($this->normalizeCode($this->input('code')) === $this->normalizeCode($current)) {
            $this->merge(['code' => $current]);
        }
    }

    private function userMayEditCode(): bool
    {
        return (bool) $this->user()?->hasRole('admin');
    }

    private function currentCode(): ?string
    {
        $accessPoint = $this->route('access_point');

        return $accessPoint instanceof AccessPoint ? $accessPoint->code : null;
    }

    private function normalizeCode(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    public function messages(): array
    {
        return [
            'building_id.required' => 'Выберите корпус, к которому относится точка прохода.',
            'building_id.exists' => 'Такого корпуса нет.',
            'name.required' => 'Укажите название точки прохода.',
            'name.unique' => 'В этом корпусе уже есть точка прохода с таким названием.',
            'code.unique' => 'Точка прохода с таким кодом уже есть.',
        ];
    }
}
