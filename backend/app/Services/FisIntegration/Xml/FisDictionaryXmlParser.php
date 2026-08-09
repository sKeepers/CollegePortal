<?php

namespace App\Services\FisIntegration\Xml;

use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Разбор ответов ФИС ГИА и Приёма по справочникам.
 *
 * Спецификация 4.9, методы 2.8 «получение списка справочников» и 2.9
 * «получение списка элементов справочника». Ответы описаны в спецификации
 * схемами, но отдельного XSD для них ФЦТ не публикует, поэтому здесь разбор
 * по именам элементов из спецификации, а не проверка по схеме.
 *
 * Справочник направлений подготовки отдаёт запись иначе, чем все остальные:
 * вместо `ID`/`Name` там `DirectionID`, `Name`, `NewCode`, `ParentDirectionID`
 * и коды укрупнённой группы. Это тот самый справочник, из которого в портале
 * берутся специальности.
 */
class FisDictionaryXmlParser
{
    /**
     * Список справочников системы: элемент `Dictionaries`.
     *
     * @return list<array{code:string,name:string}>
     */
    public function parseDictionaryList(string $xml): array
    {
        $xpath = $this->load($xml, 'Dictionaries');
        $this->assertNoError($xpath);

        $items = [];
        foreach ($xpath->query('//Dictionary') as $node) {
            /** @var DOMElement $node */
            $code = $this->text($node, 'Code');
            if ($code === null) {
                continue;
            }
            $items[] = ['code' => $code, 'name' => (string) $this->text($node, 'Name')];
        }

        if ($items === []) {
            throw new FisIntegrationException('В ответе ФИС нет ни одного справочника: проверьте, что загружен ответ метода получения списка справочников.');
        }

        return $items;
    }

    /**
     * Состав справочника: элемент `DictionaryData`.
     *
     * @return array{code:?string,name:?string,kind:string,items:list<array<string,mixed>>}
     */
    public function parseDictionaryData(string $xml): array
    {
        $xpath = $this->load($xml, 'DictionaryData');
        $this->assertNoError($xpath);

        $items = [];
        $directions = 0;

        foreach ($xpath->query('//DictionaryItem') as $node) {
            /** @var DOMElement $node */
            $directionId = $this->text($node, 'DirectionID');

            if ($directionId !== null) {
                $directions++;
                $items[] = [
                    'id' => $directionId,
                    'name' => $this->text($node, 'Name'),
                    'code' => $this->text($node, 'NewCode'),
                    'qualification_code' => $this->text($node, 'QualificationCode'),
                    'ugs_code' => $this->text($node, 'UGSCode'),
                    'ugs_name' => $this->text($node, 'UGSName'),
                    'parent_id' => $this->text($node, 'ParentDirectionID'),
                ];

                continue;
            }

            $id = $this->text($node, 'ID');
            if ($id === null) {
                continue;
            }

            $items[] = ['id' => $id, 'name' => $this->text($node, 'Name')];
        }

        if ($items === []) {
            throw new FisIntegrationException('В ответе ФИС нет ни одной записи справочника: проверьте, что загружен ответ метода получения элементов справочника.');
        }

        return [
            'code' => $this->text($xpath->query('//DictionaryData')->item(0), 'Code'),
            'name' => $this->text($xpath->query('//DictionaryData')->item(0), 'Name'),
            // Смешанного справочника не бывает: либо направления подготовки,
            // либо обычные записи. Если пришло и то и другое — это не тот файл.
            'kind' => $directions > 0 ? 'directions' : 'plain',
            'items' => $items,
        ];
    }

    /** Что это за ответ — чтобы не заставлять оператора выбирать вид файла руками. */
    public function detectKind(string $xml): string
    {
        $root = $this->rootName($xml);

        return match ($root) {
            'Dictionaries' => 'dictionary_list',
            'DictionaryData' => 'dictionary_data',
            default => throw new FisIntegrationException('Не понимаю этот ответ ФИС: корневой элемент «'.$root.'». Ожидались «Dictionaries» или «DictionaryData».'),
        };
    }

    private function rootName(string $xml): string
    {
        $document = $this->document($xml);

        return $document->documentElement?->localName ?: '';
    }

    private function load(string $xml, string $expectedRoot): DOMXPath
    {
        $document = $this->document($xml);
        $root = $document->documentElement?->localName;

        // Ответ может прийти обёрнутым — например телом SOAP. Тогда нужный
        // элемент лежит внутри, и требовать его корнем неверно.
        if ($root !== $expectedRoot) {
            $xpath = new DOMXPath($document);
            if ($xpath->query('//'.$expectedRoot)->length === 0) {
                throw new FisIntegrationException('В ответе ФИС нет элемента «'.$expectedRoot.'».');
            }
        }

        return new DOMXPath($document);
    }

    private function document(string $xml): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new FisIntegrationException('Файл не разбирается как XML: '.trim($errors[0]->message ?? 'причина неизвестна'));
        }

        return $document;
    }

    /** ФИС кладёт отказ прямо в тело ответа, а не в код HTTP. */
    private function assertNoError(DOMXPath $xpath): void
    {
        $error = $xpath->query('//Error')->item(0);

        if (! $error instanceof DOMElement) {
            return;
        }

        $code = $this->text($error, 'ErrorCode');
        $text = $this->text($error, 'ErrorText');

        throw new FisIntegrationException('ФИС отказала: '.trim(($code !== null ? '['.$code.'] ' : '').($text ?: 'текст ошибки не передан')));
    }

    private function text(mixed $node, string $child): ?string
    {
        if (! $node instanceof DOMElement) {
            return null;
        }

        foreach ($node->getElementsByTagName($child) as $found) {
            if ($found->parentNode === $node) {
                $value = trim($found->textContent);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }
}
