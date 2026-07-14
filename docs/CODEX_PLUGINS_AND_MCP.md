# Plugins и MCP для Codex

Плагины не устанавливаются автоматически из репозитория.

Рекомендуется установить вручную через каталог Codex:

- Playwright interactive/browser skill для локального UI-debugging;
- Codex Security plugin, если он доступен организации;
- существующее GitHub connection сохранить.

Без текущей необходимости не устанавливать Jira, Linear, CircleCI, GitLab и дополнительные project managers.

На текущем этапе infrastructure MCP с write-доступом запрещен. Решение и допустимый будущий read-only surface описаны в `docs/adr/ADR-CODEX-INTEGRATIONS.md`.

Формат shared local environment для установленной версии Codex в рамках этой задачи надежно не подтвержден. Репозиторий предоставляет `scripts/codex/setup-*`, `check-environment-*` и `run-quality-gates-*`; подключать их в Codex app следует вручную как setup/actions commands.
