# Playwright в CollegePortal

Playwright foundation находится в `frontend/e2e/`, конфигурация — `frontend/playwright.config.js`.

Поддерживаемые projects:

- `desktop-1366`: `1366x768`;
- `desktop-1920`: `1920x1080`;
- `mobile-390`: `390x844`.

## Credentials

Пароли не хранятся в Git. Использовать переменные из `frontend/.env.e2e.example`: `E2E_BASE_URL` и пары `E2E_<ROLE>_EMAIL` / `E2E_<ROLE>_PASSWORD`. При отсутствии роли соответствующий тест корректно пропускается.

## Команды

```bash
cd frontend
npx playwright install chromium
npm run test:e2e
npm run test:e2e:smoke
npm run test:e2e:ui
npm run test:e2e:report
```

Если `E2E_BASE_URL` отсутствует, Playwright запускает локальный Vite на `127.0.0.1:4174`. CI запускает только `@smoke`, не использует UAT credentials и сохраняет report/screenshots/traces только при failure.

## Безопасность diagnostics

- Не включать API tokens, passwords, реальные email и ПДн в fixtures.
- Не снимать private documents и реальные карточки пользователей.
- Перед публикацией проверять traces, network payload и screenshots вручную.
- Использовать `helpers/diagnostics.js` для redaction текстовых diagnostics.

Публичные login/navigation smoke работают без backend credentials. Dashboard, forbidden, gate и public document verification активируются при наличии соответствующих environment variables. Document verification остается conditional до merge Document Engine в `develop`.
