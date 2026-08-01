# UAT-002: стабилизация UX, RBAC и Admissions

## Цель

UAT-002 готовит текущий DEV-стенд Admissions Foundation к ручной проверке ролями директора, приемной комиссии, преподавателя и студента без смешивания legacy-интерфейса и нового рабочего места.

## Основные изменения

- В левом меню оставлен один пользовательский пункт приемной комиссии: `Приёмная комиссия` -> `/admissions/foundation`.
- Legacy route `/admissions` сохранен для обратной совместимости и прямой диагностики, но не показывается как основной пункт меню.
- Технические подписи `Foundation`, `Person`, `Applicant`, `Backend validation`, `validation.exists` и raw `Forbidden.` скрыты из пользовательского UX там, где они появлялись в рабочих сценариях.
- People workspace использует общий draggable splitter `useResizableWorkspace`.
- Dashboard обычных ролей загружает только разрешенные API и не показывает ошибку частичной загрузки из-за отсутствующих permissions.
- QR-пропуска подключены как интеграция существующего модуля Digital Identity, без второго backend/API.
- Страница `/login` открывается с пустыми полями email/password и не содержит hardcoded учетных данных в форме.

## QR-интеграция

Существующая реализация найдена в:

- frontend routes: `frontend/src/router/routes.js`;
- личный студенческий route: `/m/student/pass`;
- административный route: `/identity/digital-passes`;
- проходная: `/access/gate`;
- мобильный сканер: `/access/mobile-scanner`;
- отчеты по проходам: `/access/reports`;
- backend endpoints: `/api/mobile/student`, `/api/digital-identities`, `/api/digital-identities/issue`, `/api/digital-identities/{id}/revoke`, `/api/digital-identities/{id}/qr`, `/api/access/scan`, `/api/access/events`, `/api/access/reports/*`;
- QR generation: `App\Services\QrSvgService`;
- QR validation and access events: `AccessGateController`.

Причина, почему текущие пользователи DEV могли не видеть QR:

- студентский QR был доступен в отдельном мобильном layout `/m/student/pass`, но не был выведен в основном role-based `AppLayout`;
- преподаватель имел административное permission `digitalpasses.manage`, из-за чего ему мог быть виден реестр всех пропусков вместо личного пропуска;
- безопасный self-scope для `GET /api/digital-identities` отсутствовал.

Исправление:

- добавлен пункт `Мой QR-пропуск` для ролей `student`, `teacher`, `hr`;
- добавлена страница `/identity/my-pass`, использующая существующий `digital-identities` API в режиме `mine`;
- `GET /api/digital-identities*` допускает self-доступ через `view_own_data`, но контроллер фильтрует только собственные `student`/`teacher`/`person_id` записи;
- QR чужого пропуска запрещен на backend;
- raw token не отдается в JSON пользователю без `digitalpasses.manage`;
- административный реестр остался доступен только через `digitalpasses.manage`.

## Матрица меню

Подробная матрица зафиксирована в [Role Menu Matrix](ROLE_MENU_MATRIX.md).

Ключевые правила:

- Student: `Мой QR-пропуск`.
- Teacher/Employee: `Мой QR-пропуск`.
- Admin/Security: `Цифровые пропуска`, `Проходная`, `Мобильный сканер`, `Отчеты по проходам`.
- Обычный Student/Teacher не видит административные страницы проходной.

## Безопасность QR

- QR содержит технический token, без ФИО, телефона, email, группы, паспорта или других ПДн.
- Личные QR-коды используют короткоживущий формат `CP2`, обновляются каждые 30 секунд и принимаются проходной только один раз.
- Статические token и устаревший формат `CP1` не принимаются проходной.
- Сервер валидирует QR через `AccessGateController` и хранит отметку об использованном CP2 payload до истечения его TTL.
- Статусы `active`, `suspended`, `revoked`, `expired` учитываются текущей реализацией.
- Отзыв выполняется через существующий endpoint `/api/digital-identities/{id}/revoke`.
- Self-пользователь не получает raw token в JSON и не может запросить QR чужой записи.

## Проверки

UAT-002.2 проверен на DEV в Compose-контейнерах:

- `php artisan db:seed --class=RoleSeeder` применен, чтобы убрать `journal.view` у student-ролей;
- `php artisan test`: `337 passed (2150 assertions)`;
- `npm run build`: успешно;
- `GET /health/live`: `200`.

Остается ручной mobile smoke на телефоне:

- student видит QR на первом экране `/m/student`, а код обновляется без перезагрузки страницы;
- `/identity/my-pass` обновляет QR автоматически;
- `/identity/my-pass` на экранах до 520px выводит QR и обратный отсчет до обновления выше metadata-карточек;
- student не видит «Журнал» и прямой маршрут `/journal` ведет к forbidden;
- `/access/mobile-scanner` скрывает desktop-инструменты header на ширине до 520px.
- Личный QR имеет явную кнопку «Обновить код».
- Student route `/schedule` перенаправляется в мобильный блок расписания `/m/student#schedule`.
- Mobile scanner использует compact camera viewport, скрывает вторичные scanner badges на первом экране и ограничивает распознавание до одного кадра в 350ms.
- Desktop student сохраняет полный маршрут `/schedule`; на phone/tablet student получает личное мобильное расписание.
- `/journal` для student перенаправляется к личной успеваемости `/m/student#journal`, а mobile schedule поддерживает предыдущую и следующую даты.
- Нажатие на оценку student открывает личную карточку с дисциплиной, датой, преподавателем, типом и комментарием оценки.
- Responsive foundation определяет `phone`, `tablet`, `desktop-hd`, `desktop-fullhd` и `desktop-ultrawide`; phone/tablet автоматически используют personal student schedule и mobile access scanner.

## Smoke checklist

1. `admin@college-portal.local`:
   - видит `/identity/digital-passes`;
   - видит `/access/gate`;
   - видит `/access/mobile-scanner`;
   - видит `/access/reports`;
   - может открыть `/admissions/foundation`.
2. `admission@college-portal.local`:
   - видит один пункт `Приёмная комиссия`;
   - открывает `/admissions/foundation`;
   - не видит административные страницы проходной.
3. `teacher@college-portal.local`:
   - видит `Мой QR-пропуск`;
   - не видит `Цифровые пропуска`, `Проходная`, `Мобильный сканер`, `Отчеты по проходам`;
   - dashboard не показывает ошибку частичной загрузки из-за RBAC.
4. `student@college-portal.local`:
   - видит `Мой QR-пропуск`;
   - может открыть `/m/student/pass`;
   - не видит административные страницы проходной.

## Ограничения

- Сравнение с рабочим стендом `https://192.168.34.104:5443/` требует доступа к текущему deployment/code этого стенда. В текущем репозитории переиспользована найденная существующая реализация Digital Identity и проходной.
- PROD и UAT не затрагиваются.
