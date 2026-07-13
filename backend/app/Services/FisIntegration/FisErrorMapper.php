<?php

namespace App\Services\FisIntegration;

class FisErrorMapper
{
    public function message(?string $code, ?string $fallback = null): string
    {
        return match ($code) {
            'xsd_missing' => 'Не загружена официальная XSD-схема ФИС.',
            'payload_missing' => 'XML-пакет еще не сформирован.',
            'production_blocked' => 'Отправка в продуктивный контур заблокирована.',
            default => $fallback ?: 'Неизвестная ошибка ФИС.',
        };
    }
}
