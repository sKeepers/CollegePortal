# EPIC-001: Performance Review

Дата: 08.07.2026.  
Окружение: DEV `/srv/college-dev`.

## Краткий вывод

Производительность достаточна для MVP и пилотной загрузки данных. Основные риски появятся при реальных объемах: импорт больших файлов, отчеты, журнал, расписание, audit log, access events и frontend bundle.

## Backend: запросы и N+1

### Положительно

Многие контроллеры уже используют eager loading:

- Groups: `educationProgram.specialty`, `curator`;
- Admissions: `educationProgram.specialty`, `events`, `documents`;
- Curriculum: `educationProgram.specialty`, `items.subject`;
- TeachingLoad: `teacher`, `items.subject`, `items.group`;
- Exams: `group`, `subject`, `teacher`, `classroom`, `results.student`;
- FRDO/FIS: связанные records и validation errors;
- Schedule: `group`, `teacher`, `subject`, `classroom`.

### Риски N+1

- Resource-классы могут обращаться к отношениям, если контроллер забыл `with()`.
- AccessEventResource получает owner/photo через polymorphic-like lookup; на больших списках проходной это может стать дорогим.
- Import history с большими JSON payload может быть тяжелым для списка.
- Mobile Student собирает расписание, оценки и посещаемость несколькими запросами. Для MVP нормально, но нужен профиль на реальных данных.

### Рекомендации

1. Добавить Laravel query log/profiling для UAT с реальными данными.
2. Проверить Access Reports на 10k/100k событий.
3. Для audit/import history ограничить список полей в index и грузить большие JSON только в detail.
4. Добавить feature/performance smoke tests для тяжелых списков после пилотной загрузки.

## Индексы

### Уже есть важные индексы

- `digital_identities.token` unique.
- `digital_identities`: `entity_type/entity_id`, `status`, `issued_at`.
- `access_events`: `entity_type/entity_id`, `result/event_time`, `digital_identity_id/event_time`.
- `import_jobs`: `data_type`, `mode`, `status`, `data_type/created_at`.
- `audit_logs`: `action`, `entity_type`, `entity_id`, `module`, `request_id`, `module/action/created_at`, `entity_type/entity_id`.
- `schedule_lessons`: индексы по group/teacher/classroom + date/time.

### Что проверить после реальных данных

- Индекс для `access_events.event_time`, если отчеты часто фильтруются только по периоду.
- Индекс для `audit_logs.created_at`, если журнал часто открывается без module/action.
- Индексы для search/filter полей students/teachers/applicants при больших объемах.
- Индексы для импортируемых ключей: email, code, name там, где это допустимо бизнес-логикой.

## Кэширование

### Уже есть

- Reference Data имеет кэширование через `ReferenceService`.
- Settings Center может использоваться как источник конфигурации вместо разбросанных значений.

### Рекомендации

- Кэшировать публичные settings.
- Кэшировать справочники на frontend между страницами.
- Не кэшировать персональные данные без явного TTL и invalidation.
- Для Dashboard использовать короткий TTL по role-specific summary.

## Импорт

### Риски

- Universal import читает файл и хранит preview/errors/result в JSON полях `import_jobs`.
- XLSX читается первым листом без фоновой очереди.
- Большие файлы могут дать долгий request time и большой JSON в БД.

### Рекомендации

1. Ввести лимит строк для sync-import на MVP, например 5k строк.
2. Для больших файлов перейти к queued import jobs.
3. Хранить подробные errors отдельно от `import_jobs`, если объем ошибок станет большим.
4. Добавить batch progress и cancellation.

## Frontend bundle

Текущий build проходит, но Vite предупреждает о chunk больше 500 KB. Крупные факторы:

- legacy `App.vue`;
- синхронные imports большинства страниц в router;
- Quasar bundle;
- большие CRUD-страницы.

Рекомендации:

1. Перевести routes на lazy loading: `() => import('../pages/...')`.
2. Разделить legacy route в отдельный chunk.
3. Lazy-load тяжелые admin modules: import, audit, users, reference.
4. Lazy-load FRDO/FIS/Graduation/Exams.
5. После каждого шага проверять `npm run build` и размер chunks.

## Отчеты

FRDO, FIS, access reports, attendance/grades reports могут стать тяжелыми при реальных данных.

Рекомендации:

- pagination везде по умолчанию;
- export выполнять stream response или queued export;
- не строить большие JSON в памяти без необходимости;
- для аналитики готовить summary tables/materialized views только после появления реальных требований.

## Приоритетный план performance

1. Router lazy loading.
2. Разделение UniversalImportService и queued import для больших файлов.
3. Audit/import index payload optimization.
4. Профиль Access Reports на реальных данных.
5. Индексы после анализа реальных фильтров.
