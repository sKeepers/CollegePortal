<?php

namespace App\Services\FisIntegration;

use App\Models\Admissions\AdmissionApplication;
use App\Models\FisOutboundPackage;
use App\Services\FisIntegration\Xml\ApplicationsWriter;
use App\Services\FisIntegration\Xml\CompositionBlockers;
use App\Services\FisIntegration\Xml\XmlFieldWriter;
use XMLWriter;

/**
 * Готово ли заявление к выгрузке в ФИС ГИА и Приёма.
 *
 * Зачем отдельная проверка. Требования схемы ФИС к заявлению живут в сборщике
 * пакета, и до сих пор оператор узнавал о недостающем **при сборке** — то есть
 * когда заявлений уже сотни и непонятно, чьё чинить. Карточка абитуриента при
 * этом выглядела заполненной.
 *
 * Почему не написан свой список проверок. Он неминуемо разошёлся бы со сборкой:
 * схема меняется, правки вносят в сборщик, а вторая копия правил тихо устаревает
 * — и карточка обещала бы готовность, которой нет. Поэтому проверка **гоняет тот
 * же сборщик** на одном заявлении и в память, не записывая ничего. Расхождение
 * между «карточка говорит готово» и «пакет не собрался» так невозможно.
 */
class FisApplicationReadinessService
{
    public function __construct(private readonly ApplicationsWriter $applications)
    {
    }

    /**
     * @return array{ready:bool,blockers:list<array{code:string,field:string,message:string,entity:?string}>}
     */
    public function check(AdmissionApplication $application): array
    {
        $blockers = new CompositionBlockers();

        // Пакет не сохраняется: он нужен сборщику только чтобы знать год кампании
        // и тип. Записи в базе от проверки не остаётся.
        $package = new FisOutboundPackage([
            'package_type' => 'admission',
            'admission_year' => $application->admission_year,
        ]);

        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('PackageData');

        // `?? 0`, а не `getKey()` как есть: у несохранённого заявления ключа нет,
        // и `null` означал бы «проверить всю кампанию» — то есть ответ про чужие
        // заявления вместо этого. Ноль не совпадает ни с чем, и проверка честно
        // говорит, что заявление не зарегистрировано.
        $this->applications->write($writer, new XmlFieldWriter($writer, $blockers), $blockers, $package, (int) ($application->getKey() ?? 0));

        $writer->endElement();
        $writer->endDocument();
        // Результат сборки не нужен — нужен только список препятствий.
        $writer->flush();

        return [
            'ready' => ! $blockers->any(),
            'blockers' => $blockers->all(),
        ];
    }
}
