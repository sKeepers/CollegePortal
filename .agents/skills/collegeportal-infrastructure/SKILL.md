---
name: collegeportal-infrastructure
description: Анализировать и изменять инфраструктуру CollegePortal с явными границами среды. Использовать для SSH, Docker, installer, Gateway, firewall, TLS и environment inventory; не использовать для PROD-изменений без отдельного разрешения или произвольного удаленного shell-доступа.
---

# Workflow infrastructure

1. Взять фактические адреса и назначения только из `docs/ENVIRONMENTS.md` и проверить их read-only.
2. Зафиксировать host, OS, path, version, branch, container/health status и authorized scope.
3. Разделить Windows build host, Linux DEV, UAT, Gateway, monitoring и PROD.
4. Перед изменениями подготовить backup/rollback, health check и минимальный change set.
5. Не менять firewall, routes, certificates, services и credentials вслепую.
6. Проверить Docker/installer/Gateway локально или на явно разрешенной среде; логировать только redacted diagnostics.
7. Обновить environment/runbook документацию и выполнить security checks.

## Stop-gates

Остановиться при неидентифицированной среде, отсутствии backup/rollback, необходимости root/PROD без разрешения, риске потери доступа, секрете в выводе или расхождении фактической и документированной топологии.

## Отчет

Указать среду, исходное состояние, команды без секретов, изменения, health, rollback, ограничения и commit/PR.
