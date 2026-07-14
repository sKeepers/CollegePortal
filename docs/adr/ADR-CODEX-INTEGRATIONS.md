# ADR: интеграции Codex

Статус: принято для foundation.

## Решение

- GitHub используется через существующее подключение и `gh` только для поддерживаемых операций.
- Browser testing выполняется Playwright.
- Infrastructure MCP на текущем этапе не создается.
- Будущий Infrastructure MCP допускается только read-only и с redacted output.

Допустимые будущие tools:

- `get_environment_status`;
- `get_container_status`;
- `get_release_version`;
- `get_health`;
- `get_recent_redacted_errors`;
- `run_safe_smoke`.

Запрещенные capabilities:

- arbitrary shell и root execution;
- чтение private keys и raw credentials;
- destructive deployment;
- production DB writes.

## Причина

Ограниченный read-only surface снижает риск ошибочного деплоя, утечки ПДн и обхода утвержденных installer/Gateway workflows. Любое расширение требует отдельного threat review и ADR.
