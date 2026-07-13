# Support / Поддержка

[Русский](#русский) | [English](#english)

## Русский

CollegePortal сейчас находится на этапе **private RC/UAT**. Поддержка ведется через private GitHub Issues, UAT Center и согласованные внутренние каналы проекта.

### Что прикладывать к ошибке

- версия и build;
- роль пользователя;
- страница или API endpoint;
- шаги воспроизведения;
- ожидаемый результат;
- фактический результат;
- критичность;
- environment: браузер, ОС, DEV/UAT, Docker/installer;
- обезличенный скриншот или фрагмент лога, если нужен.

### Что нельзя прикладывать

- реальные базы, backups, dumps;
- `.env`, пароли, токены, приватные ключи и сертификаты;
- реальные XLS/XLSX/CSV импорты и экспорты;
- документы абитуриентов, фотографии, private storage;
- скриншоты с персональными данными.

### Эксплуатационные инциденты

Для проблем установки, обновления, backup/restore или health-check соберите:

- версию release archive;
- вывод `sudo /opt/college-portal/installer/check.sh`;
- статус контейнеров;
- затронутый URL/API;
- обезличенные логи.

Полезные документы:

- [docs/UAT_EXECUTION_GUIDE.md](docs/UAT_EXECUTION_GUIDE.md)
- [docs/KNOWN_LIMITATIONS.md](docs/KNOWN_LIMITATIONS.md)
- [docs/INSTALLATION_ACCEPTANCE_TEST.md](docs/INSTALLATION_ACCEPTANCE_TEST.md)
- [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md)

## English

CollegePortal is currently in private RC/UAT. Use private GitHub Issues, the UAT Center and agreed internal project channels for support. Include version, build, role, page/API, reproduction steps, expected and actual results, severity and sanitized evidence. Never attach secrets, real personal data, dumps, backups, `.env`, tokens or private documents.
