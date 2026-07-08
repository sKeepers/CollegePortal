<?php

namespace App\Services\Import;

use Illuminate\Database\Eloquent\Model;

interface ImportHandlerInterface
{
    public function type(): string;
    public function label(): string;
    public function modelClass(): string;
    public function keyFields(): array;
    public function fields(): array;
    public function templateHeaders(): array;
    public function templateExample(): array;
    public function prepare(array $data): array;
    public function rules(): array;
    public function findExisting(array $data): ?Model;
    public function payload(array $data, bool $update = false): array;
    public function import(array $data, string $mode): string;
    public function businessValidationErrors(array $data): array;
}
