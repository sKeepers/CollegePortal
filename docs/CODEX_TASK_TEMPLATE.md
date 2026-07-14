# Шаблон задачи Codex

## Короткий шаблон

```text
TASK-ID: <ID>
Среда: <DEV/Windows build host>
База: origin/develop
Цель: <проверяемый результат>
Разрешено: <scope>
Запрещено: main, UAT, PROD, ПДн, secrets, destructive Git
Проверки: targeted/full tests, build, smoke, diff check, secret scan, CI
Git: feature/<task>, commit message, PR -> develop
Stop-gates: dirty/conflict, red tests, secrets, missing credentials, scope expansion
Отчет: environment, commits, files, tests/build/UAT/security, PR/CI, limitations
```

## Полный шаблон

```text
Настройки Codex:
- skill/agents:
- max parallel read-only analyses:

TASK-ID:
Среда:
Исходная ветка/commit:
Цель:
Контекст:

Разрешено:
-

Запрещено:
- main/UAT/PROD без отдельного разрешения;
- destructive Git;
- ПДн, secrets и private storage;

Функциональные требования:
1.

Безопасность:
- RBAC/object scope;
- Audit/redaction;
- uploads/private files;

Тесты:
- migration smoke;
- regression/feature tests;
- full backend suite;
- frontend build;

Browser UAT:
- роли;
- routes;
- 1366x768, 1920x1080, 390x844;
- console/network/loading/empty/error/forbidden;

Документация:
-

Git:
- branch/worktree;
- commit;
- PR -> develop;
- green CI;

Stop-gates:
- dirty/conflicting worktree;
- red tests/build/CI;
- secret/PII finding;
- unavailable credentials;
- breaking change или PROD scope;

Итоговый отчет:
- среда, ветка, base/final commits;
- root cause/изменения/миграции;
- tests/assertions/build/browser/security;
- Issue/PR/CI/hash;
- ограничения и ручные шаги.
```
