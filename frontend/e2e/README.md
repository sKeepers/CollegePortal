# Playwright E2E

E2E использует только обезличенные данные. Реальные пароли, токены, ПДн, screenshots private documents и traces с API payload запрещено хранить в Git.

## Запуск

```bash
cd frontend
cp .env.e2e.example .env.e2e.local
npx playwright install chromium
npm run test:e2e:smoke
```

Если `E2E_BASE_URL` не задан, Playwright сам запускает Vite на `127.0.0.1:4174`. Ролевые тесты пропускаются, пока соответствующая пара `E2E_<ROLE>_EMAIL` / `E2E_<ROLE>_PASSWORD` не передана через environment/secret store.

Структура каталогов делится по ролям и модулям. Общие login/API/assertion/redaction helpers находятся в `helpers/`, а только безопасные настройки ролей — в `fixtures/`.
