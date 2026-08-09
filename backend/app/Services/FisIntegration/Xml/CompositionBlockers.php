<?php

namespace App\Services\FisIntegration\Xml;

/**
 * Причины, по которым пакет нельзя собрать.
 *
 * Сборка не останавливается на первой находке: оператору нужен весь список, а не
 * первое препятствие, иначе он будет узнавать о недостающих данных по одному.
 */
class CompositionBlockers
{
    /** @var list<array{code:string,field:string,message:string,entity:?string}> */
    private array $items = [];

    public function add(string $code, string $field, string $message, ?string $entity = null): void
    {
        $key = $code.'|'.$field.'|'.$entity;

        foreach ($this->items as $item) {
            if ($item['code'].'|'.$item['field'].'|'.$item['entity'] === $key) {
                return;
            }
        }

        $this->items[] = ['code' => $code, 'field' => $field, 'message' => $message, 'entity' => $entity];
    }

    public function any(): bool
    {
        return $this->items !== [];
    }

    /** @return list<array{code:string,field:string,message:string,entity:?string}> */
    public function all(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }
}
