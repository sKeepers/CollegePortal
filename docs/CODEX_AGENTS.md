# Project-scoped agents Codex

Конфигурация находится в `.codex/config.toml`, определения — в `.codex/agents/`. Agents наследуют модель родительской задачи; лимит — четыре потока, глубина — один уровень.

| Agent | Назначение |
| --- | --- |
| `code-explorer` | карта routes, stores, services, models и tests |
| `architect` | доменная модель, миграции и границы модулей |
| `backend-reviewer` | Laravel/PostgreSQL/API/RBAC/Audit review |
| `frontend-uat` | browser-only Playwright, console/network, responsive |
| `security-reviewer` | threat-oriented security review |
| `release-reviewer` | CI, installer, artifact, checksum и rollback |

Рекомендуемый порядок:

1. Исследовать задачу через `code-explorer`.
2. Для доменного изменения проверить план через `architect`.
3. После реализации запустить `backend-reviewer` и `security-reviewer`.
4. Для UI использовать `frontend-uat`.
5. Перед release использовать `release-reviewer`.

Agents используются для независимого read-heavy анализа. Параллельное редактирование одних файлов запрещено; основной worker обязан дождаться отчетов и принять окончательное решение.
