<?php

namespace App\Support\Admissions;

/**
 * Канонический источник системных справочников приемной комиссии.
 */
class AdmissionReferenceCatalogs
{
    /**
     * Возвращает системный набор справочников приемной комиссии.
     *
     * @return array<int, array{code:string,name:string,description:string,items:array<int,array{code:string,name:string,metadata?:array<string,mixed>}>}>
     */
    public static function catalogs(): array
    {
        return [
            self::catalog('admission_application_statuses', 'Статусы заявлений приемной комиссии', 'Жизненный цикл заявления абитуриента.', [
                ['draft', 'Черновик', ['tone' => 'neutral', 'terminal' => false]],
                ['new', 'Новое', ['tone' => 'info', 'terminal' => false]],
                ['documents_pending', 'Документы ожидаются', ['tone' => 'warning', 'terminal' => false]],
                ['documents_received', 'Получение документов подтверждено', ['tone' => 'info', 'terminal' => false]],
                ['documents_review', 'Документы на проверке', ['tone' => 'info', 'terminal' => false]],
                ['documents_incomplete', 'Неполный комплект', ['tone' => 'warning', 'terminal' => false]],
                ['ready_for_competition', 'Готово к конкурсу', ['tone' => 'success', 'terminal' => false]],
                ['recommended', 'Рекомендовано', ['tone' => 'success', 'terminal' => false]],
                ['order_prepared', 'В проекте приказа', ['tone' => 'info', 'terminal' => false]],
                ['enrolled', 'Зачислено', ['tone' => 'success', 'terminal' => true]],
                ['rejected', 'Отказано', ['tone' => 'danger', 'terminal' => true]],
                ['withdrawn', 'Отозвано', ['tone' => 'neutral', 'terminal' => true]],
                ['archived', 'Архив', ['tone' => 'neutral', 'terminal' => true]],
            ]),
            self::catalog('applicant_statuses', 'Статусы абитуриентов', 'Операционные статусы профиля абитуриента.', [
                ['active', 'Активен', ['tone' => 'success']],
                ['duplicate_candidate', 'Возможный дубль', ['tone' => 'warning']],
                ['archived', 'Архив', ['tone' => 'neutral']],
                ['blocked', 'Заблокирован', ['tone' => 'danger']],
            ]),
            self::catalog('application_choice_statuses', 'Статусы выбранных программ', 'Статусы выбранных образовательных программ.', [
                ['active', 'Активно', ['tone' => 'success']],
                ['excluded', 'Исключено из конкурса', ['tone' => 'neutral']],
                ['recommended', 'Рекомендовано', ['tone' => 'success']],
                ['rejected', 'Отклонено', ['tone' => 'danger']],
            ]),
            self::catalog('applicant_document_types', 'Типы документов абитуриента', 'Обязательные и дополнительные документы приемной комиссии.', [
                ['passport', 'Паспорт', ['required' => true, 'sensitive' => true]],
                ['snils', 'СНИЛС', ['required' => true, 'sensitive' => true]],
                ['education_document', 'Документ об образовании', ['required' => true, 'sensitive' => true]],
                ['photo', 'Фотография', ['required' => true, 'sensitive' => false]],
                ['medical_certificate', 'Медицинская справка', ['required' => false, 'sensitive' => true]],
                ['personal_data_consent', 'Согласие на обработку ПДн', ['required' => true, 'sensitive' => true]],
                ['benefit_confirmation', 'Подтверждение льготы', ['required' => false, 'sensitive' => true]],
                ['target_agreement', 'Договор целевого обучения', ['required' => false, 'sensitive' => true]],
            ]),
            self::catalog('applicant_document_statuses', 'Статусы документов абитуриента', 'Статусы registry-записей документов заявления.', [
                ['expected', 'Ожидается', ['tone' => 'neutral']],
                ['received', 'Получен', ['tone' => 'info']],
                ['verified', 'Проверен', ['tone' => 'success']],
                ['rejected', 'Отклонен', ['tone' => 'danger']],
                ['replaced', 'Заменен', ['tone' => 'neutral']],
            ]),
            self::catalog('achievement_types', 'Типы индивидуальных достижений', 'Классификация индивидуальных достижений абитуриента.', [
                ['olympiad', 'Олимпиада', ['requires_verification' => true]],
                ['gto', 'ГТО', ['requires_verification' => true]],
                ['volunteering', 'Волонтерство', ['requires_verification' => true]],
                ['professional_achievement', 'Профильное достижение', ['requires_verification' => true]],
                ['portfolio', 'Портфолио', ['requires_verification' => true]],
            ]),
            self::catalog('admission_exam_types', 'Типы экзаменов приемной комиссии', 'Вступительные испытания и результаты конкурса.', [
                ['entrance_exam', 'Вступительное испытание'],
                ['gia', 'ГИА'],
                ['interview', 'Собеседование'],
                ['creative_exam', 'Творческое испытание'],
                ['portfolio_review', 'Проверка портфолио'],
            ]),
            self::catalog('competition_types', 'Типы конкурсов', 'Типы конкурсных потоков приемной кампании.', [
                ['general', 'Общий конкурс'],
                ['budget', 'Бюджет'],
                ['contract', 'Договор'],
                ['target_quota', 'Целевая квота'],
                ['benefit_quota', 'Льготная квота'],
            ]),
            self::catalog('competition_statuses', 'Статусы конкурсов', 'Жизненный цикл конкурсного списка.', [
                ['draft', 'Проект', ['tone' => 'neutral']],
                ['open', 'Открыт', ['tone' => 'info']],
                ['calculation', 'Расчет', ['tone' => 'warning']],
                ['published', 'Опубликован', ['tone' => 'success']],
                ['closed', 'Закрыт', ['tone' => 'neutral']],
            ]),
            self::catalog('admission_order_types', 'Типы приказов приемной комиссии', 'Административные типы приказов приемной кампании.', [
                ['enrollment', 'Зачисление'],
                ['rejection', 'Отказ'],
                ['cancellation', 'Отмена'],
                ['change', 'Изменение'],
            ]),
            self::catalog('admission_order_statuses', 'Статусы приказов приемной комиссии', 'Жизненный цикл проекта приказа.', [
                ['draft', 'Проект', ['tone' => 'neutral']],
                ['approval', 'На согласовании', ['tone' => 'warning']],
                ['approved', 'Утвержден', ['tone' => 'success']],
                ['cancelled', 'Отменен', ['tone' => 'danger']],
            ]),
            self::catalog('enrollment_statuses', 'Статусы зачисления', 'Статусы применения результата зачисления.', [
                ['prepared', 'Подготовлено', ['tone' => 'neutral']],
                ['applied', 'Применено', ['tone' => 'success']],
                ['cancelled', 'Отменено', ['tone' => 'danger']],
                ['error', 'Ошибка', ['tone' => 'danger']],
            ]),
            self::catalog('base_education_types', 'База поступления', 'Образовательная база абитуриента.', [
                ['basic_general', 'Основное общее образование'],
                ['secondary_general', 'Среднее общее образование'],
                ['secondary_vocational', 'Среднее профессиональное образование'],
                ['higher', 'Высшее образование'],
            ]),
            self::catalog('quota_types', 'Типы квот', 'Квоты и основания участия в конкурсе.', [
                ['general', 'Общий конкурс'],
                ['target', 'Целевая квота'],
                ['benefit', 'Льгота'],
                ['special', 'Особая квота'],
            ]),
            self::catalog('admission_sources', 'Источники заявлений', 'Источник создания абитуриента или заявления.', [
                ['manual', 'Ручной ввод'],
                ['fis_import', 'Импорт ФИС'],
                ['applicant_portal', 'Личный кабинет абитуриента'],
                ['paper_archive', 'Бумажный архив'],
            ]),
            self::catalog('admission_rejection_reasons', 'Причины отказа приемной комиссии', 'Нормализованные причины отказа или отклонения заявления.', [
                ['documents_incomplete', 'Неполный комплект документов'],
                ['failed_exam', 'Не пройдено вступительное испытание'],
                ['no_places', 'Нет свободных мест'],
                ['withdrawn_by_applicant', 'Заявление отозвано абитуриентом'],
                ['duplicate_application', 'Дублирующее заявление'],
                ['invalid_data', 'Недостоверные данные'],
                ['other', 'Иная причина'],
            ]),
        ];
    }

    /**
     * Возвращает коды admissions-справочников для ограничения read-only API.
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return array_column(self::catalogs(), 'code');
    }

    /**
     * Собирает описание каталога в формате, пригодном для seeders и миграций.
     *
     * @param array<int, array{0:string,1:string,2?:array<string,mixed>}> $items
     * @return array{code:string,name:string,description:string,items:array<int,array{code:string,name:string,metadata:array<string,mixed>}>}
     */
    private static function catalog(string $code, string $name, string $description, array $items): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'items' => array_map(
                fn (array $item): array => [
                    'code' => $item[0],
                    'name' => $item[1],
                    'metadata' => $item[2] ?? [],
                ],
                $items,
            ),
        ];
    }
}
