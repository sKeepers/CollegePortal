# BULK-001: Групповые операции

## Назначение

Групповые операции позволяют безопасно выполнять однотипные действия над выбранными заявлениями абитуриентов и студентами без массового удаления данных. Все операции проходят через два этапа: `preview` и `apply`.

## Общий поток

1. Пользователь выбирает строки на странице или выбирает все записи по текущему фильтру.
2. Frontend отправляет `POST /api/admissions/bulk/preview` или `POST /api/students/bulk/preview`.
3. Backend возвращает отчет: выбрано, будет изменено, пропущено, ошибки, первые 10 примеров.
4. Пользователь подтверждает действие.
5. Frontend отправляет `POST /api/.../bulk/apply`.
6. Backend выполняет операцию в транзакции и пишет запись в Audit Log.

Запрос поддерживает два режима выбора:

```json
{
  "ids": [1, 2, 3],
  "action": "change_status",
  "payload": { "status": "accepted" }
}
```

или:

```json
{
  "filter": { "status": "new", "search": "Иванов" },
  "action": "mark_recommended",
  "payload": {}
}
```

## Приемная комиссия

Поддерживаются операции:

- `change_status` - массовое изменение статуса заявления;
- `mark_documents_provided` - отметка всех обязательных документов как полученных;
- `mark_recommended` - отметка заявлений как рекомендованных к зачислению;
- `assign_program` - назначение образовательной программы или конкурса;
- `export_selected` - экспорт выбранных заявлений в CSV;
- `enroll_selected` - массовое зачисление абитуриентов в студенты.

`enroll_selected` проверяет комплектность документов, наличие группы, отсутствие дублей студентов по `person_id`, email и ФИО + дате рождения. Если у заявления нет `person_id`, используется Person Foundation: ищется возможная Person, при однозначном совпадении профиль связывается, иначе создается новая Person.

## Студенты

Поддерживаются операции:

- `assign_group` - назначение группы;
- `change_status` - изменение статуса;
- `change_course` - изменение курса;
- `change_education_form` - изменение формы обучения;
- `change_funding_form` - изменение формы финансирования;
- `issue_digital_passes` - выпуск QR-пропусков без дублей активных пропусков;
- `archive_selected` - архивирование без удаления;
- `export_selected` - экспорт выбранных студентов в CSV с маскированием контактов.

## Права доступа

Admissions:

- `admissions.bulk_status`
- `admissions.bulk_documents`
- `admissions.bulk_recommend`
- `admissions.bulk_assign`
- `admissions.bulk_export`
- `admissions.bulk_enroll`

Students:

- `students.bulk_group`
- `students.bulk_status`
- `students.bulk_course`
- `students.bulk_education`
- `students.bulk_passes`
- `students.bulk_archive`
- `students.bulk_export`

Роли:

- admin: все операции;
- admission: массовые операции приемной комиссии;
- study/deputy: массовые операции студентов;
- director: экспорт без изменения данных;
- teacher/student/security: без доступа к массовым изменениям.

## Безопасность

- Массовое удаление не реализовано.
- Preview не изменяет БД.
- Apply выполняется транзакционно.
- Каждая apply-операция пишет Audit Log.
- CSV-экспорт маскирует чувствительные контактные данные там, где это требуется.
- Полные СНИЛС, паспортные данные и адреса в preview не выводятся.

## ADM-DOCS-001: массовые операции по документам Admissions

Admissions bulk API дополнен операциями по конкретному типу документа: `mark_document_type_received`, `send_document_type_review`, `verify_document_type`, `reject_document_type`. Все операции работают через preview/apply, принимают `payload.document_type`, а reject требует `payload.reason`. Preview не создает registry-записи; apply создает недостающую registry-строку и пишет Audit Log.
