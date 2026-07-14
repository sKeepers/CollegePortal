# Приказы студентов

Для документов добавлена foundation-сущность `student_orders`.

Типы приказов:

- `enrollment`;
- `transfer`;
- `course_promotion`;
- `academic_leave`;
- `return_from_academic_leave`;
- `dismissal`;
- `reinstatement`;
- `graduation`;
- `personal_data_change`.

В DOCS-ENGINE-001 приказы используются read-only как источник номера и даты приказа о зачислении и перевода на текущий курс.
