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
 * **Конкурс — свойство условия приёма, а не программы.** У одной программы их
 * бывает несколько сразу: бюджет и платное, очное и заочное. Ключ сопоставления
 * поэтому включает форму обучения и источник финансирования — ровно так, как
 * конкурс понимает сама ФИС в `FinSourceEduForm`.
 *
 * Неоднозначность осталась ровно там, где она настоящая: два конкурса на одну
 * программу с одинаковой формой и одинаковым источником различить нечем. Такие
 * возвращаются списком, чтобы решение принял человек. Угадать здесь — значит
 * подать заявление в чужой конкурс.
 */
class FisCompetitiveGroupIntakeService
{
    public const EXTERNAL_TYPE = 'fis:CompetitiveGroupUID';

    public function __construct(private readonly FisCompetitiveGroupParser $parser)
    {
    }

    /**
     * Условие приёма, под которым живёт конкурс: форма обучения и источник
     * финансирования в идентификаторах ФИС. Оба могут отсутствовать — тогда
     * конкурс один на программу, и область пустая, как у прежних записей.
     */
    public static function scope(?string $educationFormId, ?string $educationSourceId): string
    {
        $form = trim((string) $educationFormId);
        $source = trim((string) $educationSourceId);

        return $form === '' && $source === '' ? '' : $form.'|'.$source;
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
                        'scope' => $item['scope'],
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

            // Конкурсы одной программы разводятся условием приёма. Остаётся
            // неоднозначным только то, что и правда неразличимо: два конкурса с
            // одинаковой формой обучения и одинаковым источником финансирования.
            $byScope = [];

            foreach ($candidates as $group) {
                $byScope[self::scope($group['education_form_id'], $group['education_source_id'])][] = $group;
            }

            foreach ($byScope as $scope => $scoped) {
                if (count($scoped) > 1) {
                    $ambiguous[] = [
                        'education_program_id' => $programId,
                        'education_program' => $program?->name,
                        'candidates' => array_map(fn (array $group): array => [
                            'competitive_group_uid' => $group['uid'],
                            'competitive_group_name' => $group['name'],
                            'education_form_id' => $group['education_form_id'],
                            'education_source_id' => $group['education_source_id'],
                        ], $scoped),
                        'reason' => 'У программы несколько конкурсов с одинаковой формой обучения и одинаковым источником финансирования — различить их нечем, выберите нужный вручную.',
                    ];

                    continue;
                }

                $group = $scoped[0];
                $willMap[] = [
                    'education_program_id' => $programId,
                    'education_program' => $program?->name,
                    'competitive_group_uid' => $group['uid'],
                    'competitive_group_name' => $group['name'],
                    'campaign_uid' => $group['campaign_uid'],
                    'education_form_id' => $group['education_form_id'],
                    'education_source_id' => $group['education_source_id'],
                    'scope' => $scope,
                ];
            }
        }

        return [
            'competitive_groups' => count($groups),
            'will_map' => $willMap,
            'ambiguous' => $ambiguous,
            'unlinked' => $unlinked,
        ];
    }
}
