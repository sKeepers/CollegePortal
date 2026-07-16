# Dynamic QR Protocol

## Формат

```text
CP2:<base64url-json-payload>.<base64url-hmac-sha256>
```

Payload содержит только технические поля:

- `v` — версия token protocol;
- `sub` — `people.id`;
- `n` — nonce;
- `iat` — issued_at Unix timestamp;
- `exp` — expires_at Unix timestamp.

Payload не содержит ФИО, группу, телефон, email, документы, адрес, СНИЛС или паспортные данные.

## TTL

- TTL: 30 секунд.
- Clock skew tolerance: 5 секунд.
- После истечения TTL token отклоняется как `expired_token`.

## Replay protection

Backend сохраняет SHA-256 token hash, nonce, issued_at/expires_at и used_at. После успешного прохода `used_at` заполняется. Повторный scan того же token отклоняется как `replayed_token`.

## Legacy fallback

`CP1:<token>` и plain token поддерживаются только для старых `digital_identities.token`. Новый основной режим — `CP2`.
