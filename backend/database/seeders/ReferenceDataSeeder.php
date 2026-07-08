<?php

namespace Database\Seeders;

use App\Models\ReferenceCatalog;
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
                    ['code' => 'classroom', 'name' => 'Аудиторная'],
                    ['code' => 'individual', 'name' => 'Индивидуальная'],
                    ['code' => 'consultation', 'name' => 'Консультации'],
                    ['code' => 'exam', 'name' => 'Экзамены'],
                    ['code' => 'methodical', 'name' => 'Методическая работа'],
                ],
            ],
            [
                'code' => 'student_statuses',
                'name' => 'Статусы студентов',
                'description' => 'Основные статусы контингента.',
                'items' => [
                    ['code' => 'active', 'name' => 'Обучается'],
                    ['code' => 'academic_leave', 'name' => 'Академический отпуск'],
                    ['code' => 'expelled', 'name' => 'Отчислен'],
                    ['code' => 'graduated', 'name' => 'Выпускник'],
                    ['code' => 'transferred', 'name' => 'Переведен'],
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
                    ['code' => 'exam', 'name' => 'Экзамен'],
                    ['code' => 'credit', 'name' => 'Зачет'],
                    ['code' => 'differentiated_credit', 'name' => 'Дифференцированный зачет'],
                    ['code' => 'gia', 'name' => 'ГИА'],
                ],
            ],
            [
                'code' => 'diploma_statuses',
                'name' => 'Статусы дипломов',
                'description' => 'Жизненный цикл диплома.',
                'items' => [
                    ['code' => 'draft', 'name' => 'Черновик'],
                    ['code' => 'ready', 'name' => 'Готов'],
                    ['code' => 'issued', 'name' => 'Выдан'],
                    ['code' => 'revoked', 'name' => 'Аннулирован'],
                ],
            ],
            [
                'code' => 'applicant_application_statuses',
                'name' => 'Статусы заявлений абитуриентов',
                'description' => 'Статусы работы приемной комиссии.',
                'items' => [
                    ['code' => 'new', 'name' => 'Новое'],
                    ['code' => 'incomplete', 'name' => 'Неполный комплект'],
                    ['code' => 'ready_for_enrollment', 'name' => 'Готов к зачислению'],
                    ['code' => 'enrolled', 'name' => 'Зачислен'],
                    ['code' => 'rejected', 'name' => 'Отклонен'],
                ],
            ],
        ];
    }
}
