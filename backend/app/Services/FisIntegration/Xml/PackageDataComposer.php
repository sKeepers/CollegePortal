<?php

namespace App\Services\FisIntegration\Xml;

use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use XMLWriter;

/**
 * Сборка элемента «PackageData» метода импорта ФИС ГИА и Приёма 4.9.
 *
 * Корень схемы — «Root» с блоком авторизации и вложенным «PackageData». Портал
 * собирает только «PackageData»: логин и пароль ФИС живут на шлюзовом узле в
 * ЗКСПД и в портал не передаются. Схема объявляет «PackageData» глобальным
 * элементом, поэтому такой документ проверяется по официальной XSD сам по себе,
 * а блок авторизации добавляет шлюз перед отправкой.
 */
class PackageDataComposer
{
    public const TYPE_INSTITUTION_PROGRAMS = 'institution-programs';
    public const TYPE_APPLICATIONS = 'applications';

    /** @var list<string> */
    public const SUPPORTED_TYPES = [
        self::TYPE_INSTITUTION_PROGRAMS,
        self::TYPE_APPLICATIONS,
    ];

    /**
     * Типы, которых в методе импорта нет. Пояснение важнее отказа: иначе
     * следующий человек будет искать раздел, которого не существует.
     *
     * @var array<string, string>
     */
    private const UNSUPPORTED_TYPES = [
        'gia' => 'Метод импорта ФИС ГИА и Приёма 4.9 принимает сведения приёмной кампании: кампании, объём приёма, конкурсы, образовательные программы, заявления и приказы. Раздела для результатов ГИА колледжа в схеме нет — «ГИА» в названии системы относится к ЕГЭ и ОГЭ, которые в неё вносит РЦОИ. Пакет ГИА портала остаётся внутренним отчётом и в этот метод не отправляется.',
        'gia-results' => 'Метод импорта ФИС ГИА и Приёма 4.9 принимает сведения приёмной кампании: кампании, объём приёма, конкурсы, образовательные программы, заявления и приказы. Раздела для результатов ГИА колледжа в схеме нет — «ГИА» в названии системы относится к ЕГЭ и ОГЭ, которые в неё вносит РЦОИ. Пакет ГИА портала остаётся внутренним отчётом и в этот метод не отправляется.',
    ];

    public function __construct(
        private readonly InstitutionProgramsWriter $programs,
        private readonly ApplicationsWriter $applications,
    ) {
    }

    public function compose(FisOutboundPackage $package): PackageComposition
    {
        $type = (string) $package->package_type;

        if (isset(self::UNSUPPORTED_TYPES[$type])) {
            throw new FisIntegrationException(self::UNSUPPORTED_TYPES[$type]);
        }

        if (! in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new FisIntegrationException('Тип пакета «'.$type.'» не поддержан сборкой XML. Доступны: '.implode(', ', self::SUPPORTED_TYPES).'.');
        }

        $blockers = new CompositionBlockers();
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('PackageData');

        $fields = new XmlFieldWriter($writer, $blockers);

        $counts = match ($type) {
            self::TYPE_INSTITUTION_PROGRAMS => $this->programs->write($writer, $fields, $blockers, $package),
            self::TYPE_APPLICATIONS => $this->applications->write($writer, $fields, $blockers, $package),
        };

        $writer->endElement();
        $writer->endDocument();

        return new PackageComposition($writer->outputMemory(), $blockers->all(), $counts);
    }
}
