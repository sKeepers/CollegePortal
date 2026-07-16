# Dynamic QR Protocol

## Формат

Основной формат ACCESS-002.3:

```text
CP2:<opaque-base64url-token>
```

Текущий token содержит 32 ASCII-символа после префикса `CP2:`. Полная длина QR payload — 36 символов.

QR payload не содержит ФИО, группу, телефон, email, документы, адрес, СНИЛС, паспортные данные, `people.id`, timestamps или JSON payload.

## Server-side state

Backend хранит состояние короткоживущего token в `access_pass_tokens`:

- `person_id`;
- SHA-256 `token_hash`;
- `nonce`;
- `version`;
- `issued_at`;
- `expires_at`;
- `used_at`;
- `revoked_at`.

Raw token не сохраняется в БД, audit payload или logs.

## TTL

- TTL: 30 секунд.
- Clock skew tolerance: 5 секунд.
- После истечения TTL token отклоняется как `expired_token`.

## Replay protection

После успешного прохода `used_at` заполняется. Повторный scan того же token отклоняется как `replayed_token`.

## QR rendering

QR генерируется через поддерживаемую библиотеку `endroid/qr-code`:

- Error Correction Level: M;
- размер отображения: не менее 360x360 px;
- quiet zone: не менее 40 px, что соответствует минимум 4 модулям для текущей плотности CP2;
- SVG: `shape-rendering="crispEdges"`;
- PNG: черный на белом фоне, без логотипов, прозрачности и градиентов.

## Signed CP2 compatibility

Старый формат `CP2:<base64url-json-payload>.<base64url-hmac-sha256>` остается только compatibility branch для ранее выпущенных DEV token в период перехода. Новые token выпускаются только в opaque формате.

## Legacy fallback

`CP1:<token>` и plain token поддерживаются только для старых `digital_identities.token`. Новый основной режим — короткоживущий `CP2`.
