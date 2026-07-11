<?php

namespace Database\Seeders;

use App\Models\ReferenceCatalog;
use App\Services\ReferenceService;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalogs() as $catalogData) {
            $items = $catalogData['items'];
            unset($catalogData['items']);

            $catalog = ReferenceCatalog::query()->updateOrCreate(
                ['code' => $catalogData['code']],
                [
                    'name' => $catalogData['name'],
                    'description' => $catalogData['description'] ?? null,
                    'is_system' => true,
                ],
            );

            foreach ($items as $index => $item) {
                $catalog->items()->updateOrCreate(
                    ['code' => $item['code']],
                    [
                        'name' => $item['name'],
                        'sort_order' => $item['sort_order'] ?? ($index + 1) * 10,
                        'is_active' => $item['is_active'] ?? true,
                        'metadata' => [
                            'is_system' => true,
                            ...($item['metadata'] ?? []),
                        ],
                    ],
                );
            }

            ReferenceService::forget($catalog->code);
        }
    }

    private function catalogs(): array
    {
        return [
            [
                'code' => 'education_forms',
                'name' => 'Формы обучения',
                'description' => 'Очная, очно-заочная и заочная формы обучения.',
                'items' => [
                    ['code' => 'full_time', 'name' => 'Очная'],
                    ['code' => 'part_time', 'name' => 'Очно-заочная'],
                    ['code' => 'distance', 'name' => 'Заочная'],
                ],
            ],
            [
                'code' => 'funding_forms',
                'name' => 'Формы финансирования',
                'description' => 'Бюджетное и договорное обучение.',
                'items' => [
                    ['code' => 'budget', 'name' => 'Бюджет'],
                    ['code' => 'contract', 'name' => 'Договор'],
                    ['code' => 'targeted', 'name' => 'Целевое обучение'],
                ],
            ],
            [
                'code' => 'lesson_types',
                'name' => 'Виды занятий',
                'description' => 'Типы занятий для расписания и журнала.',
                'items' => [
                    ['code' => 'lecture', 'name' => 'Лекция'],
                    ['code' => 'practice', 'name' => 'Практическое занятие'],
                    ['code' => 'lab', 'name' => 'Лабораторная работа'],
                    ['code' => 'individual', 'name' => 'Индивидуальное занятие'],
                    ['code' => 'consultation', 'name' => 'Консультация'],
                ],
            ],
            [
                'code' => 'teaching_load_types',
                'name' => 'Виды учебной нагрузки',
                'description' => 'Классификация нагрузки преподавателей.',
                'items' => [
                    ['code' => 'classroom', 'name' => 'Аудиторная', 'metadata' => ['tone' => 'info', 'value_field' => 'name']],
                    ['code' => 'individual', 'name' => 'Индивидуальная', 'metadata' => ['tone' => 'success', 'value_field' => 'name']],
                    ['code' => 'consultation', 'name' => 'Консультации', 'metadata' => ['tone' => 'warning', 'value_field' => 'name']],
                    ['code' => 'exam', 'name' => 'Экзамены', 'metadata' => ['tone' => 'danger', 'value_field' => 'name']],
                    ['code' => 'methodical', 'name' => 'Методическая работа', 'metadata' => ['tone' => 'neutral', 'value_field' => 'name']],
                ],
            ],
            [
                'code' => 'student_statuses',
                'name' => 'Статусы студентов',
                'description' => 'Основные статусы контингента.',
                'items' => [
                    ['code' => 'active', 'name' => 'Обучается', 'metadata' => ['tone' => 'success']],
                    ['code' => 'academic_leave', 'name' => 'Академический отпуск', 'metadata' => ['tone' => 'warning']],
                    ['code' => 'expelled', 'name' => 'Отчислен', 'metadata' => ['tone' => 'danger']],
                    ['code' => 'graduated', 'name' => 'Выпускник', 'metadata' => ['tone' => 'info']],
                    ['code' => 'transferred', 'name' => 'Переведен', 'metadata' => ['tone' => 'neutral']],
                ],
            ],
            [
                'code' => 'expulsion_reasons',
                'name' => 'Причины отчисления',
                'description' => 'Основания для отчисления студента.',
                'items' => [
                    ['code' => 'own_request', 'name' => 'По собственному желанию'],
                    ['code' => 'academic_failure', 'name' => 'Академическая неуспеваемость'],
                    ['code' => 'discipline', 'name' => 'Нарушение дисциплины'],
                    ['code' => 'transfer', 'name' => 'Перевод в другую организацию'],
                ],
            ],
            [
                'code' => 'academic_leave_reasons',
                'name' => 'Причины академического отпуска',
                'description' => 'Причины оформления академического отпуска.',
                'items' => [
                    ['code' => 'medical', 'name' => 'Медицинские показания'],
                    ['code' => 'family', 'name' => 'Семейные обстоятельства'],
                    ['code' => 'military', 'name' => 'Военная служба'],
                    ['code' => 'other', 'name' => 'Иная причина'],
                ],
            ],
            [
                'code' => 'document_types',
                'name' => 'Типы документов',
                'description' => 'Документы абитуриентов, студентов и выпускников.',
                'items' => [
                    ['code' => 'passport', 'name' => 'Паспорт'],
                    ['code' => 'snils', 'name' => 'СНИЛС'],
                    ['code' => 'education_certificate', 'name' => 'Документ об образовании'],
                    ['code' => 'medical_certificate', 'name' => 'Медицинская справка'],
                    ['code' => 'diploma', 'name' => 'Диплом'],
                ],
            ],
            [
                'code' => 'exam_types',
                'name' => 'Виды экзаменов',
                'description' => 'Формы промежуточной и итоговой аттестации.',
                'items' => [
                    ['code' => 'exam', 'name' => 'Экзамен', 'metadata' => ['tone' => 'danger']],
                    ['code' => 'credit', 'name' => 'Зачет', 'metadata' => ['tone' => 'success']],
                    ['code' => 'differentiated_credit', 'name' => 'Дифференцированный зачет', 'metadata' => ['tone' => 'warning']],
                    ['code' => 'gia', 'name' => 'ГИА', 'metadata' => ['tone' => 'info']],
                ],
            ],
            [
                'code' => 'diploma_statuses',
                'name' => 'Статусы дипломов',
                'description' => 'Жизненный цикл диплома.',
                'items' => [
                    ['code' => 'draft', 'name' => 'Черновик', 'metadata' => ['tone' => 'neutral']],
                    ['code' => 'ready', 'name' => 'Готов', 'metadata' => ['tone' => 'info']],
                    ['code' => 'issued', 'name' => 'Выдан', 'metadata' => ['tone' => 'success']],
                    ['code' => 'revoked', 'name' => 'Аннулирован', 'metadata' => ['tone' => 'danger']],
                ],
            ],
            [
                'code' => 'applicant_document_types',
                'name' => 'Типы документов абитуриента',
                'description' => 'Обязательные и дополнительные документы заявления абитуриента.',
                'items' => [
                    ['code' => 'passport', 'name' => 'Паспорт', 'metadata' => ['required' => true, 'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'], 'max_size_mb' => 10, 'tone' => 'info']],
                    ['code' => 'snils', 'name' => 'СНИЛС', 'metadata' => ['required' => true, 'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'], 'max_size_mb' => 10, 'tone' => 'info']],
                    ['code' => 'education_document', 'name' => 'Документ об образовании', 'metadata' => ['required' => true, 'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'], 'max_size_mb' => 15, 'tone' => 'info']],
                    ['code' => 'photo', 'name' => 'Фотография', 'metadata' => ['required' => true, 'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'], 'max_size_mb' => 5, 'tone' => 'info']],
                    ['code' => 'medical_certificate', 'name' => 'Медицинская справка', 'metadata' => ['required' => true, 'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'], 'max_size_mb' => 10, 'tone' => 'info']],
                    ['code' => 'personal_data_consent', 'name' => 'Согласие на ПДн', 'metadata' => ['required' => true, 'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png', 'webp'], 'max_size_mb' => 10, 'tone' => 'info']],
                ],
            ],
            [
                'code' => 'applicant_application_statuses',
                'name' => 'Статусы заявлений абитуриентов',
                'description' => 'Статусы работы приемной комиссии.',
                'items' => [
                    ['code' => 'new', 'name' => 'Новое', 'metadata' => ['tone' => 'info']],
                    ['code' => 'accepted', 'name' => 'Принято', 'metadata' => ['tone' => 'success']],
                    ['code' => 'needs_clarification', 'name' => 'Требуется уточнение', 'metadata' => ['tone' => 'warning']],
                    ['code' => 'enrolled', 'name' => 'Зачислен', 'metadata' => ['tone' => 'success']],
                    ['code' => 'rejected', 'name' => 'Отклонено', 'metadata' => ['tone' => 'danger']],
                    ['code' => 'incomplete', 'name' => 'Неполный комплект', 'is_active' => false, 'metadata' => ['tone' => 'warning']],
                    ['code' => 'ready_for_enrollment', 'name' => 'Готов к зачислению', 'is_active' => false, 'metadata' => ['tone' => 'success']],
                ],
            ],
        ];
    }
}
