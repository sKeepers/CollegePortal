<?php

namespace App\Services\FisIntegration\Xml;

use App\Services\FisIntegration\Exceptions\FisIntegrationException;

/**
 * Официальная XSD метода импорта ФИС ГИА и Приёма, приведённая к виду,
 * который понимает libxml2.
 *
 * В официальном файле семь ограничений `xs:pattern` записаны в синтаксисе .NET
 * и используют опережающую проверку `(?!\s*$)`. В регулярных выражениях XSD 1.0
 * такой конструкции нет, `^` и `$` тоже не якоря, поэтому libxml2 отказывается
 * компилировать схему целиком — не отдельное поле, а весь файл. Проверено на
 * libxml 2.9.14: `schemaValidate` возвращает «Invalid Schema».
 *
 * Смысл шаблона — «значение не пустое и не состоит из одних пробелов». В XSD 1.0
 * это записывается как `\s*\S[\s\S]*`: шаблон и так применяется ко всему
 * значению, якоря не нужны. Подстановка выполняется в памяти, официальный файл
 * остаётся нетронутым и служит эталоном.
 */
class FisXsdSchema
{
    /**
     * Замены выполняются по порядку: шаблон в скобках содержит внутри себя
     * шаблон без скобок, поэтому длинный идёт первым.
     *
     * @var array<string, string>
     */
    private const PATTERN_REPLACEMENTS = [
        'value="(^(?!\s*$).+)?"' => 'value="(\s*\S[\s\S]*)?"',
        'value="^(?!\s*$).+"' => 'value="\s*\S[\s\S]*"',
    ];

    private ?string $source = null;

    /** @var array<string, int> */
    private array $applied = [];

    public function path(): ?string
    {
        $path = config('fis_api.xsd_path');

        return $path && is_file($path) ? (string) $path : null;
    }

    public function loaded(): bool
    {
        return $this->path() !== null;
    }

    public function fingerprint(): ?string
    {
        $path = $this->path();

        return $path === null ? null : hash_file('sha256', $path);
    }

    /**
     * Исходный текст схемы, пригодный для компиляции libxml.
     *
     * @throws FisIntegrationException
     */
    public function source(): string
    {
        if ($this->source !== null) {
            return $this->source;
        }

        $path = $this->path();
        if ($path === null) {
            throw new FisIntegrationException('Официальная XSD ФИС не найдена. Проверьте FIS_API_XSD_PATH.');
        }

        $source = (string) file_get_contents($path);
        $applied = [];

        foreach (self::PATTERN_REPLACEMENTS as $search => $replace) {
            $count = 0;
            $source = str_replace($search, $replace, $source, $count);
            $applied[$search] = $count;
        }

        // Если ФЦТ добавит новый шаблон с опережающей проверкой, схема снова
        // перестанет компилироваться. Лучше сказать об этом прямо здесь, чем
        // получить невнятное «Invalid Schema» при проверке пакета.
        if (str_contains($source, '(?!') || str_contains($source, '(?=')) {
            throw new FisIntegrationException('В официальной XSD появился неизвестный шаблон с опережающей проверкой. Требуется разобрать его вручную и дополнить FisXsdSchema.');
        }

        $this->applied = $applied;
        $this->source = $source;

        return $source;
    }

    /**
     * Что именно подменено — уходит в событие проверки пакета, чтобы правка
     * схемы была видна в журнале, а не только в коде.
     *
     * @return array<string, int>
     */
    public function compatibilityNotes(): array
    {
        if ($this->source === null) {
            $this->source();
        }

        return $this->applied;
    }
}
