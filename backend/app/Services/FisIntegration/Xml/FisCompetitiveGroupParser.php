<?php

namespace App\Services\FisIntegration\Xml;

use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Разбор конкурсов из сведений об образовательной организации.
 *
 * Конкурсы заводятся в самой ФИС, портал их не ведёт, но без `CompetitiveGroupUID`
 * заявление не проходит схему. Метод получения сведений по ОО (спецификация 4.9,
 * пункты 2.7 и 2.13) отдаёт их разделом `AdmissionInfo/CompetitiveGroups` — в той
 * же форме, что описана в XSD метода импорта.
 *
 * Связь с образовательной программой портала лежит прямо в ответе: в
 * `EduPrograms/EduProgram/UID` стоит тот идентификатор, который портал сам выдаёт
 * в исходящем пакете. Догадываться не нужно.
 */
class FisCompetitiveGroupParser
{
    /**
     * @return list<array{uid:string,name:?string,campaign_uid:?string,direction_id:?string,education_form_id:?string,education_source_id:?string,education_level_id:?string,program_uids:list<string>}>
     */
    public function parse(string $xml): array
    {
        $xpath = new DOMXPath($this->document($xml));
        $groups = [];

        foreach ($xpath->query('//CompetitiveGroup') as $node) {
            /** @var DOMElement $node */
            $uid = $this->text($node, 'UID');

            if ($uid === null) {
                continue;
            }

            $groups[] = [
                'uid' => $uid,
                'name' => $this->text($node, 'Name'),
                'campaign_uid' => $this->text($node, 'CampaignUID'),
                'direction_id' => $this->text($node, 'DirectionID'),
                'education_form_id' => $this->text($node, 'EducationFormID'),
                'education_source_id' => $this->text($node, 'EducationSourceID'),
                'education_level_id' => $this->text($node, 'EducationLevelID'),
                'program_uids' => $this->programUids($node),
            ];
        }

        if ($groups === []) {
            throw new FisIntegrationException('В файле нет ни одного конкурса: проверьте, что загружены сведения об образовательной организации.');
        }

        return $groups;
    }

    /** @return list<string> */
    private function programUids(DOMElement $group): array
    {
        $uids = [];

        foreach ($group->getElementsByTagName('EduProgram') as $program) {
            $uid = $this->text($program, 'UID');

            if ($uid !== null && ! in_array($uid, $uids, true)) {
                $uids[] = $uid;
            }
        }

        return $uids;
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

    private function text(DOMElement $node, string $child): ?string
    {
        foreach ($node->getElementsByTagName($child) as $found) {
            if ($found->parentNode === $node) {
                $value = trim($found->textContent);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }
}
