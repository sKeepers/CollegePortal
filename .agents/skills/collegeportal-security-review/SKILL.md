---
name: collegeportal-security-review
description: Выполнять read-only threat-oriented review CollegePortal. Использовать для проверки RBAC/IDOR, validation, secrets, ПДн, uploads, private storage, QR/tokens, redacted logging и dependency risks; не использовать для эксплуатации уязвимостей или изменения PROD.
---

# Workflow security review

1. Работать read-only по умолчанию и зафиксировать scope, environment и trust boundaries.
2. Проверить authentication, permissions, object scope и IDOR на API и UI.
3. Проверить validation, mass assignment, transactions, idempotency и error disclosure.
4. Проверить secret handling, PII minimization, private storage, download authorization и retention.
5. Проверить uploads: extension/MIME/size, path traversal, active content и public exposure.
6. Проверить QR/token entropy, replay/expiry/revocation и отсутствие ПДн в payload.
7. Проверить Audit/log redaction, dependencies, CI artifacts, screenshots и traces.
8. Оформить findings по severity с evidence, impact и конкретной remediation; не писать style-only замечания.

## Stop-gates

Остановиться при необходимости активной эксплуатации, чтения raw credentials/private keys, доступа к PROD или данных реальных пользователей.

## Отчет

Сначала findings по severity, затем подтвержденные controls, test gaps, residual risk и рекомендуемый порядок исправлений.
