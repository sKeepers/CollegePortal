# Security Policy / Политика безопасности

[Русский](#русский) | [English](#english)

## Русский

Текущая поддерживаемая версия: **0.8.0-rc6 / Private Release Candidate**.

### Как сообщить об уязвимости

На этапе private RC/UAT не публикуйте сведения об уязвимостях, exploit details, персональные данные или секреты в открытых комментариях. Сообщайте о проблемах владельцам private-репозитория через приватные GitHub-каналы или согласованный внутренний канал связи.

В сообщении укажите:

- версию и build;
- затронутую страницу/API;
- роль пользователя;
- краткое описание риска;
- шаги воспроизведения на обезличенных данных;
- ожидаемый и фактический результат.

### Нельзя публиковать

- `.env`, пароли, токены, SSH-ключи, TLS private keys и сертификаты;
- реальные базы данных, дампы, backups и runtime storage;
- реальные XLS/XLSX/CSV импорты или экспорты;
- документы абитуриентов, фотографии и private storage;
- скриншоты с паспортными данными, СНИЛС, адресами, телефонами или полными идентификаторами.

### Обязательные проверки перед релизом или деплоем

- `php artisan test`;
- `npm run build`;
- `git diff --check`;
- secret scan / gitleaks, если доступен;
- проверка `.gitignore` и `git status --ignored`;
- проверка release artifacts на отсутствие секретов и runtime-данных;
- backup перед обновлением или переносом.

См. также:

- [docs/PRODUCTION_SECURITY_CHECKLIST.md](docs/PRODUCTION_SECURITY_CHECKLIST.md)
- [docs/PRODUCTION_DEPLOYMENT_READINESS.md](docs/PRODUCTION_DEPLOYMENT_READINESS.md)
- [docs/INSTALLATION_ACCEPTANCE_TEST.md](docs/INSTALLATION_ACCEPTANCE_TEST.md)

## English

Supported private RC: **0.8.0-rc6**.

Report security issues through private repository maintainer channels or the agreed internal communication channel. Do not publish secrets, credentials, personal data, exploit details, database dumps, backups, private documents or screenshots with personal data in issues or pull requests.

Before release or deployment, run tests, frontend build, whitespace checks, secret scanning where available, `.gitignore` review and release artifact review.
