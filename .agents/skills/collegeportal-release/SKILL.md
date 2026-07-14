---
name: collegeportal-release
description: Готовить и проверять контролируемые релизы CollegePortal. Использовать для version/changelog, release archive, checksum, install/update/rollback и UAT readiness; не использовать для обычной feature-разработки или несанкционированного обновления main/UAT/PROD.
---

# Workflow release

1. Подтвердить green `develop`, release scope, version и отсутствие незавершенных блокеров.
2. Обновить version, changelog и release notes без секретов и ПДн.
3. Выполнить migrations/tests/build, installer checks и security scan.
4. Собрать воспроизводимый source/release archive и SHA-256; проверить структуру и forbidden files.
5. Проверить install, update, health, backup/restore и rollback на разрешенном стенде.
6. Обновлять UAT только после подтверждения. Обновлять `main` и создавать Release только после approval и green CI.

## Stop-gates

Остановиться при красном CI, неготовой миграции, отсутствии backup/rollback, несовпадении checksum, секрете в artifact или отсутствии разрешения на UAT/main/PROD.

## Отчет

Указать source commit, version, checksums, tests/build, install/update/rollback, UAT status, Release URL и ограничения.
