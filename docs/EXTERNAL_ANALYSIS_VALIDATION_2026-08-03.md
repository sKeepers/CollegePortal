# Проверка внешнего технического анализа

Дата проверки: 03.08.2026.  
Проверенная ветка: `feature/uat-002-1-final-stabilization`.  
Проверялась рабочая копия, включая уже существующие незакоммиченные изменения других задач. Прикладной код, конфигурация и данные в рамках этой проверки не изменялись.

## Вывод

Внешний анализ от 30.07.2026 в целом верно определил препятствия для публичного PROD с реальными ПДн. Однако его главный вывод об аутентификации уже не соответствует текущему состоянию: задача `SEC-001` устранена после даты исходного анализа.

Публичное размещение с реальными персональными данными по-прежнему нельзя считать готовым. Минимальные стоп-факторы: bearer token в browser storage, незашифрованные резервные копии с `.env`, публичная выдача персональных фото, неполный HTTPS/TLS perimeter, отсутствие PostgreSQL-проверок в CI и отсутствие утверждённой реализации шифрования ПДн at rest.

Это техническая оценка, а не заключение о соответствии 152-ФЗ, требованиям ФСТЭК/ФСБ или локальным нормативным актам. До обработки реальных ПДн требуется отдельная юридическая и организационная оценка оператора ПДн.

## Статус утверждений

| Область | Статус | Проверка и вывод |
| --- | --- | --- |
| Перебор всех пользователей и bcrypt на каждый API-запрос | Устарело, устранено | `AuthenticateApiToken` ищет indexed `SHA-256` lookup hash с TTL, без перебора: `backend/app/Http/Middleware/AuthenticateApiToken.php:21-31`. Регрессия покрыта `backend/tests/Feature/AuthApiTest.php`. |
| Бессрочные токены | Устарело, устранено | Login задаёт `api_token_expires_at`; TTL по умолчанию 720 минут: `backend/app/Http/Controllers/Api/AuthController.php:35-42`, `backend/config/auth.php:119-128`. Новый login заменяет токен, logout очищает его. |
| Rate limiting отсутствует | Устарело, устранено | Login ограничен 5 запросами в минуту на `IP|email`, защищённый API 120 запросами в минуту на пользователя: `backend/app/Providers/AppServiceProvider.php:28-36`, `backend/routes/api.php:64,69`. Для публичного контура полезен дополнительный IP-only лимит на reverse proxy. |
| Bearer token в `localStorage` | Подтверждено | `frontend/src/services/api.js`, `frontend/src/stores/auth.js`. При сессионном входе используется `sessionStorage`, но JavaScript всё ещё может прочитать токен. |
| CORS не задан явно | Подтверждено | `backend/config/cors.php` отсутствует. При переходе на cookie-auth обязателен явный allowlist origins. |
| Блокировка пользователя отзовёт токен навсегда | Частично | Middleware не пропускает `is_active=false`, но hash не очищается при блокировке; после unblock старый токен действует до TTL. Нужна отдельная проверка и отзыв. |
| ПДн шифруются at rest | Не подтверждено | Нет подтверждения encrypted casts/прикладного шифрования для всех чувствительных полей. В foundation уже есть hash СНИЛС для поиска, но это не заменяет шифрование значения. |
| Backup и `.env` зашифрованы | Не подтверждено | `installer/backup.sh:17-31` создаёт открытые `database.sql`, `storage.tar.gz` и `env.protected`. Права доступа снижают риск, но не являются криптографической защитой. |
| Персональные фото публичны | Подтверждено | `PersonPhotoController` сохраняет на `public` disk: `backend/app/Http/Controllers/Api/PersonPhotoController.php:24-42`; Nginx отдаёт `/storage/`: `installer/templates/nginx-release.conf:30-33`. |
| Неполный production HTTPS/TLS perimeter | Подтверждено | Один server block слушает `80` и `443` без redirect; нет HSTS, CSP, frame/referrer/permissions headers: `installer/templates/nginx-release.conf:1-46`. |
| Грубый RBAC через разбор URL | Подтверждено | `manage_dictionaries` сопоставляется с path prefix; fallback равен `reference.manage`: `backend/app/Http/Middleware/EnsurePermission.php:100-141`. Это миграционный слой, но он остаётся источником риска. |
| Скачивания полностью защищены связями объектов | Частично | Legacy documents проверяют цепочку application/document/file. Foundation file download проверяет предметное permission, но маршрут содержит только file ID: `backend/routes/api.php:256-258`. Нужна проверка объектной изоляции по выбранной модели доступа. |
| Две несогласованные таблицы admissions | Скорректировано | Legacy и Foundation используют одну `applicant_applications`, различая `record_type`; это не две физические таблицы, но две семантики/API и миграционный риск сохраняется. |
| Все ФИС- и import-контроллеры являются дубликатами | Частично | Inbound FIS, generic legacy import и outbound FIS имеют разные назначения. Консолидация нужна только после фиксации границ и миграционного плана. |
| CI проверяет PostgreSQL | Не подтверждено | `backend/phpunit.xml:20-31` использует SQLite memory; `.github/workflows/ci.yml:23-50` не поднимает PostgreSQL service. |
| Redis заявлен и одинаково используется в DEV/PROD | Частично | В DEV compose Redis нет; в installer PROD Redis есть для cache, но очередь остаётся database. Это нужно явно документировать либо выровнять окружения. |
| Mock-интеграции отсутствуют | Не подтверждено | Outbound FIS по умолчанию использует mock, official SOAP остаётся заглушкой; mobile notifications и часть dashboard являются mock. Это допустимо для MVP при явной маркировке. |

## Приоритет и порядок

До решения задач `SEC-002`, `SEC-003`, `SEC-004`, `SEC-005` и `SEC-006` нельзя проводить интернет-пилот с реальными ПДн. Пилот с синтетическими или обезличенными данными допустим только в изолированном DEV/UAT при соблюдении текущих ограничений.

1. `SEC-002` и `SEC-004` должны быть согласованы вместе: cookie-auth требует CSRF-модели, CORS allowlist и корректного HTTPS.
2. `SEC-003`, `SEC-005` и `SEC-006` должны иметь утверждённые владельцем системы правила: перечень ПДн, срок хранения, ключи шифрования, резервное копирование и восстановление.
3. `SEC-007` и `TEST-002` можно вести параллельно с предыдущими, так как они не меняют auth/storage контракт.
4. `ARCH-001`, `ARCH-002` и `OPS-001` не блокируют техническое устранение критических рисков, но обязательны до широкого пилота.

## Независимые сессии

Каждый промпт ниже предназначен для отдельного чата. Перед началом исполнитель обязан прочитать `AGENTS.md`, `docs/ACTIVE_WORK.md`, выполнить `git status --short`, `git diff --check`, `git log --oneline -10` и не отменять чужие изменения. Нельзя использовать реальные ПДн, секреты, ключи или выполнять PROD-деплой без отдельного явного поручения.

### SEC-002: Cookie-auth и защита frontend-сессии

**Зависимости:** сначала принять архитектурное решение: Laravel Sanctum SPA/session или эквивалентная серверная cookie-сессия. Не начинать параллельно с изменением Nginx в `SEC-004` без согласования контрактов.

**Критерии готовности:** token отсутствует в `localStorage` и `sessionStorage`; cookie имеет `HttpOnly`, `Secure`, подходящий `SameSite`; mutating requests защищены CSRF; logout и блокировка пользователя отзывают все действующие сессии; frontend обрабатывает только согласованные auth failures; есть feature и frontend tests.

```text
Реализуй SEC-002 в CollegePortal: замени bearer token в localStorage/sessionStorage на серверно управляемую HttpOnly Secure cookie-аутентификацию. Сначала изучи AGENTS.md, docs/ACTIVE_WORK.md, текущие AuthController, AuthenticateApiToken, frontend/src/services/api.js, frontend/src/stores/auth.js и installer Nginx. Выбери минимальный безопасный вариант на Laravel Sanctum SPA/session либо штатной Laravel session с CSRF; до правок кратко зафиксируй решение и влияние на API.

Сохрани существующие RBAC, audit и роли. Не ослабляй CSRF/CORS, не добавляй fallback на localStorage, не меняй несвязанные домены. Сделай logout и блокировку пользователя отзывом активных сессий. Добавь тесты login/me/logout, CSRF, истёкшей/отозванной сессии и блокировки пользователя. Выполни доступные тесты и frontend build; если локальные зависимости отсутствуют, точно укажи блокер. Не деплой и не коммить без отдельного поручения.
```

### SEC-003: Контракт шифрования ПДн и ключей

**Зависимости:** требуется решение владельца системы о перечне полей, сроках хранения, владельце ключей и процедуре аварийного восстановления. Это не следует угадывать в коде.

**Критерии готовности:** ADR содержит модель угроз, классификацию данных, способ поиска, ротацию и восстановление ключей; выбранный подход совместим с PostgreSQL, backups, фильтрацией и экспортом; определён план миграции без потери данных.

```text
Выполни только проектирование SEC-003 для CollegePortal, без изменения прикладного кода и миграций. Изучи модели Person/Admissions/Student/Teacher, текущие hash-поля, import/export, audit и backup scripts. Подготовь ADR: перечень чувствительных полей и файлов, модель угроз, рекомендуемый способ encryption-at-rest, детерминированные lookup hashes для поиска, key management/rotation, backup/restore, аудит доступа, retention и безопасный план миграции существующих данных.

Не утверждай соответствие 152-ФЗ без юриста/ИБ. Не включай реальные ПДн, секреты или примеры ключей. Отдели обязательные решения владельца системы от технических шагов и предложи критерии приемки будущей реализации.
```

### SEC-004: Production TLS, security headers и CORS

**Зависимости:** согласовать с `SEC-002` cookie/CSRF и точные production domains.

**Критерии готовности:** HTTP только перенаправляет на HTTPS; сертификат доверенный; TLS 1.2/1.3; HSTS включается только для валидного HTTPS-домена; CSP совместима с собранным Vue UI; заданы `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, clickjacking policy; CORS ограничен allowlist.

```text
Реализуй SEC-004 для production installer CollegePortal. Прочитай AGENTS.md, docs/ACTIVE_WORK.md, installer/install.sh, installer/templates/nginx-release.conf, frontend build configuration и текущую auth-модель. Раздели Nginx на HTTP redirect и HTTPS server block, добавь современные TLS settings и security headers. Реализуй явную Laravel CORS-конфигурацию, разрешающую только согласованные origins; не используй wildcard при credentials.

Не включай HSTS для IP/самоподписанного DEV-контура и не ломай health endpoints. CSP сначала проверь на production build: не допускай unsafe-inline без документированного обоснования. Добавь тестируемую installer/documentation проверку конфигурации и обнови production checklist. Не применяй изменения на DEV/PROD и не коммить без отдельного поручения.
```

### SEC-005: Private access для персональных фото

**Зависимости:** согласовать с UI и правилами просмотра фото по ролям.

**Критерии готовности:** новые фото хранятся вне public disk; прямой `/storage` URL не открывает ПДн; выдача проверяет permission и связь с запрошенной сущностью; старые файлы имеют безопасный миграционный план; есть позитивные и негативные API-tests.

```text
Реализуй SEC-005 в CollegePortal: переведи персональные фото Student/Teacher/Graduate из public storage в private storage и выдавай их только через авторизованный API endpoint. Изучи PersonPhotoController, модели, Resources, frontend consumers, routes, Nginx и существующую файловую модель Admissions.

Сохрани допустимый UX отображения фото, но не возвращай постоянный публичный URL. Определи минимальные предметные permissions для просмотра, проверь IDOR и связь фото с сущностью. Подготовь безопасную миграцию уже записанных photo_path без автоматического удаления данных. Добавь feature tests: разрешённый просмотр, запрещённый просмотр, несуществующий ID и отсутствие direct public access. Не меняй admission document flow, не деплой и не коммить без отдельного поручения.
```

### SEC-006: Шифрование backup и проверяемое восстановление

**Зависимости:** результаты `SEC-003` о ключах и хранении.

**Критерии готовности:** дамп, storage и configuration metadata не сохраняются открыто; ключ не находится в архиве; restore проверяет целостность и способен восстановить архив; есть retention и документированный drill восстановления на изолированном контуре.

```text
Реализуй SEC-006 для installer backup/restore CollegePortal после чтения AGENTS.md, docs/ACTIVE_WORK.md, installer/backup.sh, installer/restore.sh, scripts/deploy/backup-prod.sh и документации backup. Используй утверждённый в SEC-003 механизм шифрования. Исключи .env из обычного архива либо зашифруй отдельно; ключ расшифрования не должен попадать в backup, manifest или git.

Сохрани контроль checksum и добавь безопасную проверку расшифровки/восстановления. Документируй required environment variables, права, retention, off-site передачу и аварийное восстановление без раскрытия секретов. Добавь shell-level проверки, которые не требуют реального PROD. Не выполняй backup/restore на DEV/PROD с реальными данными и не коммить без отдельного поручения.
```

### SEC-007: Пароли, блокировка и антиавтоматизация

**Зависимости:** нет; можно выполнять параллельно с `SEC-003`--`SEC-006`.

**Критерии готовности:** единая password policy для API, installer и административного создания пользователей; production demo seed запрещён; static fallback passwords отсутствуют; блокировка отзывает auth artefacts; login лимиты защищают IP и identity без раскрытия существования email.

```text
Реализуй SEC-007 в CollegePortal. Проверь LoginRequest, AdminUserController, CreateInstallAdminCommand, DatabaseSeeder, DemoDataSeeder, UatUserSeeder, AuthController, AdminUserController и RateLimiter. Введи единый Laravel Password rule с обоснованной длиной и проверкой compromised passwords, где это поддерживается. Не требуй произвольную composition policy без обоснования.

Запрети создание demo data и известных fallback-паролей в production независимо от случайного вызова DatabaseSeeder. При блокировке пользователя отзывай его действующие токены/сессии. Усиль login throttling минимальным способом, не раскрывая, существует ли email. Добавь тесты для всех новых инвариантов. Не изменяй frontend-auth архитектуру, не деплой и не коммить без отдельного поручения.
```

### TEST-002: PostgreSQL job в CI

**Зависимости:** нет; можно выполнять параллельно с security-задачами.

**Критерии готовности:** CI поднимает PostgreSQL 17; запускает migrations и релевантные tests с `DB_CONNECTION=pgsql`; SQLite job сохраняется как быстрый; ошибка PG job блокирует merge; workflow не содержит секретов.

```text
Реализуй TEST-002 в CollegePortal: добавь отдельный GitHub Actions job для backend feature tests на PostgreSQL 17. Изучи .github/workflows/ci.yml, backend/phpunit.xml, .env.example, миграции и текущую docker/dev конфигурацию. Не заменяй SQLite полностью: сохрани быстрый job и добавь PostgreSQL coverage как отдельный обязательный сигнал.

Настрой service container, переменные test database, расширение pdo_pgsql, подготовку env и миграции. Добавь один или несколько тестов, подтверждающих PostgreSQL-специфичное поведение, которое SQLite не покрывает, только если оно уже используется приложением. Проверь YAML и выполни локально только доступные статические проверки. Не меняй production compose, не деплой и не коммить без отдельного поручения.
```

### ARCH-001: Устранение URL-based RBAC

**Зависимости:** нужна утверждённая матрица ролей и владельцы permissions. Исполнять после security-критичных задач, чтобы не смешивать рисковые изменения авторизации.

**Критерии готовности:** новые/изменённые маршруты имеют явный предметный permission; fallback по path не расширяет доступ; access, imports, audit, settings и identity разделены; есть матрица и regression tests deny/allow.

```text
Спроектируй и реализуй ARCH-001: поэтапно замени URL-prefix mapping в EnsurePermission на явные предметные permissions в routes CollegePortal. Сначала проинвентаризируй все маршруты и роли, подготовь mapping table и migration plan, совместимый с существующими role records. Затем сделай минимальный первый slice без изменения несвязанных доменов.

Не удаляй legacy permission до покрытия маршрутов и миграции ролей. Особенно изолируй access gate, import, audit, settings, digital identity и admissions. Добавь feature tests positive/negative для изменённых маршрутов и обнови RBAC docs. Не деплой и не коммить без отдельного поручения.
```

### ARCH-002: Object-level access к скачиванию файлов

**Зависимости:** определить policy: доступ по роли ко всем документам или по принадлежности оператору/подразделению.

**Критерии готовности:** каждый download/upload/delete endpoint проверяет связь маршрутных объектов и применяет policy; невозможно скачать чужой файл подстановкой ID; tests покрывают IDOR.

```text
Выполни ARCH-002: проведи инвентаризацию всех file download/upload/delete endpoints CollegePortal и реализуй object-level authorization там, где route содержит неполный контекст. Особо проверь Admissions Foundation document-files и journal files. Сначала зафиксируй целевую access policy и не предполагай, что role permission автоматически означает доступ к любому объекту.

Используй Laravel Policies/Gates или минимальный эквивалент в service layer, сохраняй private storage и корректные 403/404 по принятой security policy. Добавь IDOR regression tests с разными пользователями, ролями и несвязанными entity IDs. Не деплой и не коммить без отдельного поручения.
```

### OPS-001: Retention, imports, mocks и эксплуатационная готовность

**Зависимости:** нет, но внедрение retention ПДн согласовать с `SEC-003`.

**Критерии готовности:** import files/jobs/audit/access events имеют утверждённые сроки и scheduled cleanup; XLSX имеет размерные и ресурсные лимиты; mock UI/API явно обозначены вне PROD; DEV/PROD Redis distinction документировано; backup restore drill описан.

```text
Реализуй OPS-001 небольшими независимыми slices. Начни с чтения AGENTS.md, docs/ACTIVE_WORK.md, SECURITY_REVIEW.md, UniversalImportService, FisAdmissionsImportController, scheduler, mock notifications/dashboard и compose files. Подготовь план retention с владельцами данных и stop-gates, затем реализуй только согласованный минимальный slice: cleanup import artifacts, limits for XLSX, либо явную маркировку mock состояния.

Не удаляй данные без dry-run, configured retention period и тестов. Не объединяй inbound/outbound FIS искусственно. Явно задокументируй, что Redis в installer используется для cache, а database queue остаётся отдельным решением. Не деплой и не коммить без отдельного поручения.
```

## Отложенные решения

- MFA для привилегированных ролей рекомендуется до широкого internet-пилота, но требует отдельного решения о TOTP, recovery codes, break-glass account и поддержке пользователей.
- Консолидация legacy/Foundation admissions должна выполняться по утверждённому migration plan; нельзя удалять legacy API до миграции потребителей и данных.
- Реальный FIS/FRDO transport, уведомления и hardware integration остаются функциональными этапами, а не доказательством production readiness.
- Мониторинг, алертинг, request IDs и маскирование ПДн в audit/logs следует включить в эксплуатационный baseline до широкого пилота.

## Выполненные проверки

- Прочитаны текущие auth, routes, RBAC middleware, production Nginx, backup script, photo storage, CI и профиль PHPUnit.
- Выполнен `git diff --check`: ошибок diff не обнаружено; Git сообщил только предупреждения нормализации LF/CRLF для уже изменённых документов.
- Локальный запуск PHP/Laravel tests невозможен: `php` отсутствует в PATH. `node` `v24.16.0` и `npm` `11.13.0` доступны, но зависимости frontend не устанавливались, чтобы не создавать изменения рабочего дерева.
- DEV и PROD не изменялись.
