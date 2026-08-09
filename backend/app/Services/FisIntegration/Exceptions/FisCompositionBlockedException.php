<?php

namespace App\Services\FisIntegration\Exceptions;

/**
 * Пакет не собран: не хватает сведений. Причины передаются списком, потому что
 * оператору нужно увидеть всё сразу, а не устранять их по одной.
 */
class FisCompositionBlockedException extends FisIntegrationException
{
    /** @param  list<array{code:string,field:string,message:string,entity:?string}>  $blockers */
    public function __construct(private readonly array $blockers)
    {
        parent::__construct('Пакет не собран: не хватает сведений ('.count($blockers).').');
    }

    /** @return list<array{code:string,field:string,message:string,entity:?string}> */
    public function blockers(): array
    {
        return $this->blockers;
    }
}
