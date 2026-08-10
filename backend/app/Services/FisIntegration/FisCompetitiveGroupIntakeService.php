<?php

namespace App\Services\FisIntegration;

use App\Models\EducationProgram;
use App\Models\FisExternalMapping;
use App\Services\AuditLogService;
use App\Services\FisIntegration\Xml\FisCompetitiveGroupParser;
use App\Services\FisIntegration\Xml\InstitutionProgramsWriter;
use Illuminate\Support\Facades\DB;

/**
 * Приём конкурсов ФИС в сопоставления портала.
 *
 * `CompetitiveGroupUID` — единственное, чего не хватало исходящему пакету
 * заявлений: без него заявление не проходит схему. Конкурсы заводятся в самой
 * ФИС, и портал их не ведёт — он лишь запоминает, какой конкурс отвечает какой
 * образовательной программе.
 *
 * **Ограничение, о котором надо знать.** У одной программы в ФИС может быть
 * несколько конкурсов сразу — бюджет и платное, очное и заочное. Сопоставление
 * хранит один UID на программу, поэтому неоднозначные случаи не связываются
 * вовсе: они возвращаются списком с названиями, формой обучения и источником
 * финансирования, чтобы решение принял человек. Угадать здесь — значит подать
 * заявление в чужой конкурс.
 */
class FisCompetitiveGroupIntakeService
{
    public const EXTERNAL_TYPE = 'fis:CompetitiveGroupUID';

    public function __construct(private readonly FisCompetitiveGroupParser $parser)
    {
    }

    /** @return array<string, mixed> */
    public function preview(string $xml): array
    {
        return ['kind' => 'institution_export'] + $this->plan($this->parser->parse($xml));
    }

    /** @return array<string, mixed> */
    public function apply(string $xml, string $environment): array
    {
        $plan = $this->plan($this->parser->parse($xml));
        $mapped = 0;

        DB::transaction(function () use ($plan, $environment, &$mapped): void {
            foreach ($plan['will_map'] as $item) {
                FisExternalMapping::query()->updateOrCreate(
                    [
                        'entity_type' => EducationProgram::class,
                        'entity_id' => $item['education_program_id'],
                        'external_type' => self::EXTERNAL_TYPE,
                        'environment' => $environment,
                    ],
                    [
                        'external_id' => $item['competitive_group_uid'],
                        'metadata' => array_filter([
                            'name' => $item['competitive_group_name'],
                            'campaign_uid' => $item['campaign_uid'],
                            'education_form_id' => $item['education_form_id'],
                            'education_source_id' => $item['education_source_id'],
                        ], fn ($value): bool => $value !== null),
                    ],
                );
                $mapped++;
            }
        });

        AuditLogService::log('fis_competitive_groups', 'applied', null, null, [
            'mapped' => $mapped,
            'ambiguous' => count($plan['ambiguous']),
            'unlinked' => count($plan['unlinked']),
        ]);

        return ['kind' => 'institution_export', 'mapped' => $mapped]
            + array_diff_key($plan, ['will_map' => null]);
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    private function plan(array $groups): array
    {
        $programs = EducationProgram::query()->get()->keyBy(fn (EducationProgram $program): string => InstitutionProgramsWriter::UID_PREFIX.$program->getKey());

        /** @var array<int, list<array<string, mixed>>> $byProgram */
        $byProgram = [];
        $unlinked = [];

        foreach ($groups as $group) {
            $matched = false;

            foreach ($group['program_uids'] as $uid) {
                $program = $programs->get($uid);

                if (! $program) {
                    continue;
                }

                $matched = true;
                $byProgram[$program->getKey()][] = $group;
            }

            if (! $matched) {
                $unlinked[] = [
                    'competitive_group_uid' => $group['uid'],
                    'competitive_group_name' => $group['name'],
                    'program_uids' => $group['program_uids'],
                    'reason' => $group['program_uids'] === []
                        ? 'В конкурсе не перечислены образовательные программы.'
                        : 'Ни одна образовательная программа конкурса не найдена в портале по идентификатору.',
                ];
            }
        }

        $willMap = [];
        $ambiguous = [];

        foreach ($byProgram as $programId => $candidates) {
            $program = EducationProgram::query()->find($programId);

            if (count($candidates) > 1) {
                $ambiguous[] = [
                    'education_program_id' => $programId,
                    'education_program' => $program?->name,
                    'candidates' => array_map(fn (array $group): array => [
                        'competitive_group_uid' => $group['uid'],
                        'competitive_group_name' => $group['name'],
                        'education_form_id' => $group['education_form_id'],
                        'education_source_id' => $group['education_source_id'],
                    ], $candidates),
                    'reason' => 'У программы несколько конкурсов. Сопоставление хранит один — выберите нужный вручную.',
                ];

                continue;
            }

            $group = $candidates[0];
            $willMap[] = [
                'education_program_id' => $programId,
                'education_program' => $program?->name,
                'competitive_group_uid' => $group['uid'],
                'competitive_group_name' => $group['name'],
                'campaign_uid' => $group['campaign_uid'],
                'education_form_id' => $group['education_form_id'],
                'education_source_id' => $group['education_source_id'],
            ];
        }

        return [
            'competitive_groups' => count($groups),
            'will_map' => $willMap,
            'ambiguous' => $ambiguous,
            'unlinked' => $unlinked,
        ];
    }
}
