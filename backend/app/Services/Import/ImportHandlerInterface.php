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
    /**
     * Замечания к строке, которая **загрузится**.
     *
     * Форма та же, что у `businessValidationErrors`: поле → сообщения. Разница не в
     * форме, а в судьбе строки: ошибка её останавливает, замечание — нет.
     *
     * Нужно там, где часть строки потерялась, а сама строка законна: у школы нет номера
     * аттестата, преподаватель из списка не нашёлся, программа названа с опечаткой.
     * Отказывать целиком в таких случаях нельзя — студента не загрузить из-за школы, —
     * а молчать нельзя тем более: 22.08.2026 так исчезли 580 названий школ, и полтора
     * месяца пустой раздел объясняли тем, что данных нет ни в одном источнике.
     *
     * @return array<string, array<int, string>>
     */
    public function rowNotices(array $data): array;

    public function import(array $data, string $mode): string;
    public function businessValidationErrors(array $data): array;
}
