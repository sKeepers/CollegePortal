<?php

namespace App\Services\FisIntegration\Xml;

use App\Models\EducationProgram;
use App\Models\FisOutboundPackage;
use XMLWriter;

/**
 * Раздел «InstitutionPrograms» — образовательные программы организации.
 *
 * Единственный раздел пакета, который портал заполняет полностью из своих
 * данных: схема требует только UID, название и необязательный код.
 */
class InstitutionProgramsWriter
{
    /** @return array<string, int> */
    public function write(XMLWriter $writer, XmlFieldWriter $fields, CompositionBlockers $blockers, FisOutboundPackage $package): array
    {
        $programs = EducationProgram::query()
            ->with('specialty')
            ->where('is_active', true)
            ->when(
                $package->source_entity_type === 'education_program' && $package->source_entity_id,
                fn ($query) => $query->whereKey($package->source_entity_id),
            )
            ->orderBy('id')
            ->get();

        if ($programs->isEmpty()) {
            $blockers->add(
                'no_source_data',
                'InstitutionPrograms',
                'Нет действующих образовательных программ: пакет получится пустым, а схема ФИС требует хотя бы одну.',
            );

            return ['institution_programs' => 0];
        }

        $writer->startElement('InstitutionPrograms');

        foreach ($programs as $program) {
            $fields->context('Образовательная программа #'.$program->id);
            $writer->startElement('InstitutionProgram');
            $fields->requiredText('UID', $this->uid($program), 200);
            $fields->requiredText('Name', $program->name, 200, 'Не заполнено название образовательной программы.', minLength: 4);
            $fields->optionalText('Code', $program->specialty?->code, 10);
            $writer->endElement();
        }

        $writer->endElement();
        $fields->context('');

        return ['institution_programs' => $programs->count()];
    }

    /**
     * Приставка вынесена в константу: по этому же идентификатору сведения об
     * организации возвращают конкурс обратно, и обе стороны обязаны читать
     * его одинаково.
     */
    public const UID_PREFIX = 'education-program-';

    public function uid(EducationProgram $program): string
    {
        return self::UID_PREFIX.$program->getKey();
    }
}
