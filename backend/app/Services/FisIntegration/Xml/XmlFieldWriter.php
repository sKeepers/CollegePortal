<?php

namespace App\Services\FisIntegration\Xml;

use DateTimeInterface;
use XMLWriter;

/**
 * Запись отдельных полей пакета с оглядкой на ограничения XSD.
 *
 * Длину значения проверяем сами и при превышении добавляем причину отказа.
 * Молча обрезать нельзя: в официальном пакете это уже не форматирование, а
 * искажение сведений — обрезанный номер документа выглядит как настоящий.
 */
class XmlFieldWriter
{
    private string $entity = '';

    public function __construct(
        private readonly XMLWriter $writer,
        private readonly CompositionBlockers $blockers,
    ) {
    }

    /** Чей блок пишется сейчас — попадает в текст причины отказа. */
    public function context(string $entity): void
    {
        $this->entity = $entity;
    }

    public function optionalText(string $name, ?string $value, int $maxLength): void
    {
        if (! filled($value)) {
            return;
        }

        $this->requiredText($name, $value, $maxLength);
    }

    public function requiredText(string $name, ?string $value, int $maxLength, ?string $missingMessage = null, int $minLength = 1): void
    {
        $value = is_string($value) ? trim($value) : $value;

        if (! filled($value)) {
            $this->blockers->add(
                'field_missing',
                $name,
                $missingMessage ?: 'Не заполнено обязательное поле пакета ФИС «'.$name.'».',
                $this->entity ?: null,
            );

            return;
        }

        if (mb_strlen($value) < $minLength) {
            $this->blockers->add(
                'field_too_short',
                $name,
                'Значение поля «'.$name.'» короче '.$minLength.' символов, требуемых схемой ФИС.',
                $this->entity ?: null,
            );

            return;
        }

        if (mb_strlen($value) > $maxLength) {
            $this->blockers->add(
                'field_too_long',
                $name,
                'Значение поля «'.$name.'» длиннее '.$maxLength.' символов, допустимых схемой ФИС.',
                $this->entity ?: null,
            );

            return;
        }

        $this->writer->writeElement($name, $value);
    }

    public function requiredInt(string $name, ?int $value, string $missingMessage): void
    {
        if ($value === null) {
            $this->blockers->add('reference_missing', $name, $missingMessage, $this->entity ?: null);

            return;
        }

        $this->writer->writeElement($name, (string) $value);
    }

    public function optionalInt(string $name, ?int $value): void
    {
        if ($value === null) {
            return;
        }

        $this->writer->writeElement($name, (string) $value);
    }

    public function bool(string $name, bool $value): void
    {
        $this->writer->writeElement($name, $value ? 'true' : 'false');
    }

    public function requiredDate(string $name, mixed $value, ?string $missingMessage = null): void
    {
        $date = $this->asDate($value);

        if ($date === null) {
            $this->blockers->add(
                'field_missing',
                $name,
                $missingMessage ?: 'Не заполнена обязательная дата «'.$name.'».',
                $this->entity ?: null,
            );

            return;
        }

        $this->writer->writeElement($name, $date->format('Y-m-d'));
    }

    public function optionalDate(string $name, mixed $value): void
    {
        $date = $this->asDate($value);

        if ($date !== null) {
            $this->writer->writeElement($name, $date->format('Y-m-d'));
        }
    }

    public function requiredDateTime(string $name, mixed $value, ?string $missingMessage = null): void
    {
        $date = $this->asDate($value);

        if ($date === null) {
            $this->blockers->add(
                'field_missing',
                $name,
                $missingMessage ?: 'Не заполнена обязательная дата «'.$name.'».',
                $this->entity ?: null,
            );

            return;
        }

        $this->writer->writeElement($name, $date->format('Y-m-d\TH:i:s'));
    }

    public function optionalFloat(string $name, mixed $value): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $this->writer->writeElement($name, rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.') ?: '0');
    }

    public function element(string $name, callable $body): void
    {
        $this->writer->startElement($name);
        $body();
        $this->writer->endElement();
    }

    private function asDate(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }

        if (! filled($value) || ! is_string($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
