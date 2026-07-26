# RBAC приемной комиссии

Документ фиксирует проект ролей, разрешений, ограничений и audit-требований подсистемы «Приемная комиссия» для ADM-003. Код, миграции, backend, frontend и API-реализация в рамках документа не создаются.

Источник API: [API приемной комиссии](API_ПРИЕМНОЙ_КОМИССИИ.md).

# Роли

## Администратор

Назначение: системная настройка, права, справочники, аудит и полный доступ к данным приемной комиссии.

Разрешено:

- управление всеми заявлениями;
- управление справочниками;
- просмотр audit;
- исправление ошибочных состояний;
- настройка RBAC.

Ограничения:

- не должен использоваться для повседневной работы оператора;
- действия с приказами, зачислением и ФИС требуют подтверждения и причины.

## Ответственный секретарь приемной комиссии

Назначение: управление приемной кампанией, конкурсом, приказами и контролем ФИС.

Разрешено:

- просмотр и редактирование всех заявлений;
- проверка документов;
- управление конкурсами;
- подготовка и утверждение приказов при наличии отдельного права;
- запуск preview/apply зачисления;
- подготовка ФИС-пакетов.

Ограничения:

- не управляет системными пользователями и глобальными permissions;
- не видит чувствительные audit payload сверх своей зоны ответственности.

## Оператор приемной комиссии

Назначение: первичная регистрация абитуриентов, заявлений и документов.

Разрешено:

- создавать абитуриента;
- создавать и редактировать черновики заявлений;
- принимать документы;
- загружать файлы документов;
- видеть историю заявления.

Ограничения:

- не утверждает приказы;
- не применяет зачисление;
- не управляет конкурсными правилами;
- не запускает экспорт ФИС.

## Директор

Назначение: контроль приемной кампании и управленческий просмотр.

Разрешено:

- смотреть заявления, конкурсы, приказы и отчеты;
- смотреть Dashboard приемной комиссии;
- экспортировать управленческие отчеты при наличии права.

Ограничения:

- не создает и не редактирует заявления;
- не утверждает операционные изменения через API приемной комиссии без отдельного delegated permission;
- не применяет зачисление.

## Учебная часть

Назначение: сверка зачисленных абитуриентов и перехода в контингент студентов.

Разрешено:

- просматривать зачисления;
- проверять будущие группы и образовательные программы;
- участвовать в preview зачисления;
- видеть ошибки перехода в студентов.

Ограничения:

- не редактирует исходные заявления и документы;
- не управляет конкурсом;
- не утверждает приказы.

## Абитуриент

Назначение: будущий пользователь личного кабинета.

Разрешено:

- видеть только собственную карточку;
- видеть статус своих заявлений;
- загружать документы, если включен личный кабинет;
- видеть уведомления приемной комиссии.

Ограничения:

- не видит чужие заявления;
- не видит внутренние комментарии;
- не видит audit;
- не меняет конкурсные и приказные статусы.

# Разрешения

## Абитуриенты

| Permission | Назначение | Роли по умолчанию |
| --- | --- | --- |
| `admissions.applicant.view` | просмотр foundation-профилей абитуриентов | admin, admission, director, study, academic_office |
| `admissions.applicant.manage` | создание и связывание foundation-профилей через сервисный слой | admin, admission |

Разрешения `admissions.applicants.create`, `admissions.applicants.update` и `admissions.applicants.archive` остаются проектными именами для будущего полноценного CRUD. В BACK-002 они не реализуются, потому что API абитуриентов открыт только на чтение.

## Заявления

| Permission | Назначение | Роли по умолчанию |
| --- | --- | --- |
| `admissions.application.view` | просмотр foundation-заявлений | admin, admission, director, study, academic_office |
| `admissions.application.create` | создание черновика заявления | admin, admission |
| `admissions.application.update` | редактирование допустимых полей черновика | admin, admission |
| `admissions.application.register` | регистрация черновика заявления | admin, admission |
| `admissions.application.manage` | будущие расширенные операции над заявлениями | admin, admission |
| `admissions.applications.withdraw` | отзыв заявления | admin, admissions_secretary |
| `admissions.choice.view` | просмотр выбранных программ заявления | admin, admissions_secretary, admissions_operator, director, study |
| `admissions.choice.create` | добавление выбранной программы | admin, admissions_secretary, admissions_operator |
| `admissions.choice.update` | изменение приоритета, формы, финансирования, основания или статуса выбора | admin, admissions_secretary, admissions_operator |
| `admissions.choice.delete` | архивирование выбранной программы | admin, admissions_secretary, admissions_operator |

Разрешения `admissions.applications.*` с plural-именованием остаются проектными именами полного CRUD. В BACK-003 реализованы singular permissions `admissions.application.*` для foundation API. В BACK-004 реализованы singular permissions `admissions.choice.*` для выбранных образовательных программ заявления. Отзыв заявления, документы, конкурс и зачисление остаются planned.

## Документы

| Permission | Назначение | Роли по умолчанию |
| --- | --- | --- |
| `admissions.documents.view` | просмотр registry документов | admin, admissions_secretary, admissions_operator, director |
| `admissions.documents.receive` | отметка получения | admin, admissions_secretary, admissions_operator |
| `admissions.documents.upload` | загрузка файлов | admin, admissions_secretary, admissions_operator |
| `admissions.documents.download` | защищенное скачивание файлов | admin, admissions_secretary, admissions_operator |
| `admissions.documents.verify` | проверка или отклонение | admin, admissions_secretary |
| `admissions.documents.recalculate` | пересчет комплектности | admin, admissions_secretary |

## Достижения и экзамены

| Permission | Назначение | Роли по умолчанию |
| --- | --- | --- |
| `admissions.achievements.view` | просмотр достижений | admin, admissions_secretary, admissions_operator, director |
| `admissions.achievements.manage` | добавление и изменение достижений | admin, admissions_secretary, admissions_operator |
| `admissions.achievements.verify` | подтверждение баллов | admin, admissions_secretary |
| `admissions.exams.view` | просмотр экзаменов | admin, admissions_secretary, admissions_operator, director |
| `admissions.exams.manage` | назначение экзаменов | admin, admissions_secretary |
| `admissions.exams.result` | внесение результатов | admin, admissions_secretary |

## Конкурс

| Permission | Назначение | Роли по умолчанию |
| --- | --- | --- |
| `admissions.competitions.view` | просмотр конкурсов | admin, admissions_secretary, director, study_office |
| `admissions.competitions.manage` | создание и настройка конкурсов | admin, admissions_secretary |
| `admissions.competitions.rank` | пересчет рейтинга | admin, admissions_secretary |
| `admissions.competitions.recommend` | рекомендация к зачислению | admin, admissions_secretary |

## Приказы и зачисление

| Permission | Назначение | Роли по умолчанию |
| --- | --- | --- |
| `admissions.orders.view` | просмотр приказов | admin, admissions_secretary, director, study_office |
| `admissions.orders.create` | создание проекта приказа | admin, admissions_secretary |
| `admissions.orders.manage_items` | управление строками приказа | admin, admissions_secretary |
| `admissions.orders.approve` | утверждение приказа | admin, admissions_secretary |
| `admissions.orders.cancel` | отмена приказа | admin |
| `admissions.enroll.view` | просмотр зачислений | admin, admissions_secretary, director, study_office |
| `admissions.enroll.preview` | preview зачисления | admin, admissions_secretary, study_office |
| `admissions.enroll.apply` | применение зачисления | admin, admissions_secretary |

## ФИС и справочники

| Permission | Назначение | Роли по умолчанию |
| --- | --- | --- |
| `admissions.fis.preview` | preview ФИС-пакета | admin, admissions_secretary |
| `admissions.fis.create_package` | создание внутреннего ФИС-пакета | admin, admissions_secretary |
| `admissions.fis.validate` | проверка ФИС-пакета | admin, admissions_secretary |
| `admissions.fis.download` | скачивание подготовленного пакета | admin, admissions_secretary |
| `admissions.reference.view` | просмотр справочников | admin, admissions_secretary, admissions_operator, director, study_office |
| `admissions.reference.manage` | управление справочниками | admin |
| `admissions.events.view` | просмотр истории заявления | admin, admissions_secretary, admissions_operator, director |

# Ограничения

- Permission проверяется на backend для каждого endpoint.
- Frontend скрывает недоступные действия, но не является источником истины.
- Абитуриент видит только свои данные.
- Директор получает read-only доступ, если отдельное делегирование не выдано явно.
- Оператор не может утверждать приказ, применять зачисление и запускать ФИС-пакет.
- Утвержденные приказы и примененные зачисления не редактируются обычным CRUD.
- Файлы документов скачиваются только через защищенный backend endpoint.
- Audit payload не должен содержать полный паспорт, СНИЛС, raw ФИС payload, файлы и секреты.

# Audit

Аудит обязателен для действий:

- создание и изменение абитуриента;
- ручная привязка Person;
- создание, регистрация, изменение и отзыв заявления;
- добавление, изменение и удаление выбранной программы;
- прием, загрузка, проверка, отклонение и замена документа;
- изменение баллов достижения;
- ввод и изменение результата экзамена;
- добавление заявления в конкурс;
- пересчет рейтинга;
- рекомендация к зачислению;
- создание, изменение, утверждение и отмена приказа;
- preview и apply зачисления;
- создание, validation и скачивание ФИС-пакета;
- изменение справочников и статусов.

Минимальные поля audit-события:

| Поле | Назначение |
| --- | --- |
| `event_code` | машинный код события |
| `actor_user_id` | кто выполнил действие |
| `entity_type` | тип сущности |
| `entity_id` | ID сущности |
| `request_id` | ID запроса |
| `ip_address` | IP клиента, если доступен |
| `user_agent` | user-agent, если доступен |
| `before` | маскированный diff до изменения |
| `after` | маскированный diff после изменения |
| `reason` | причина для критичных действий |
| `created_at` | время события |

# Действия, требующие подтверждения

| Действие | Требование |
| --- | --- |
| Архивирование абитуриента | причина и подтверждение |
| Регистрация заявления | подтверждение обязательных полей |
| Отзыв заявления | причина |
| Удаление выбранной программы после включения в конкурс | причина |
| Отклонение документа | причина |
| Изменение результата экзамена | причина и audit diff |
| Пересчет рейтинга | причина |
| Рекомендация к зачислению | подтверждение |
| Утверждение приказа | подтверждение и проверка строк |
| Отмена приказа | причина, доступ только ограниченным ролям |
| Apply зачисления | preview_id, request_id, подтверждение |
| Создание ФИС-пакета | подтверждение состава данных |
| Скачивание ФИС-пакета | audit без raw персонального payload |

# Матрица read/write

| Раздел | Admin | Секретарь | Оператор | Директор | Учебная часть | Абитуриент |
| --- | --- | --- | --- | --- | --- | --- |
| Абитуриенты | RW | RW | RW | R | R | Own R |
| Заявления | RW | RW | RW limited | R | R | Own R |
| Документы | RW | RW | RW receive/upload | R | - | Own upload/R |
| Достижения | RW | RW | RW draft | R | - | Own R |
| Экзамены | RW | RW | - | R | R | Own R |
| Конкурс | RW | RW | - | R | R | Own result |
| Приказы | RW | RW | - | R | R | Own result |
| Зачисление | RW | RW | - | R | R/preview | Own result |
| ФИС | RW | RW | - | R summary | - | - |
| Справочники | RW | R | R | R | R | - |
| Audit | R | R scoped | R application events | R summary | - | - |
