# Access Control Security

## Основные гарантии

- QR не содержит персональные данные.
- Raw token не сохраняется в БД и не пишется в audit payload.
- В `access_pass_tokens` хранится только SHA-256 token hash.
- Подпись проверяется через constant-time `hash_equals`.
- TTL динамического QR: 30 секунд.
- Clock skew tolerance: 5 секунд.
- Повторное использование динамического QR отклоняется как `replayed_token`.
- Старый скриншот перестает работать после TTL.
- Scanner API защищен RBAC permission `access.scan`.
- Ручное исправление события требует `access.override` и причину.

## Ограничения MVP

- Device allowlist подготовлен через `access_devices`, но строгий allowlist пока не включен.
- Турникеты не подключаются.
- Биометрия не используется.
- Attendance bridge не меняет журнал занятий автоматически.

## Запрещено

- логировать raw token;
- добавлять ФИО, телефон, группу, документы или паспортные данные в QR;
- сохранять screenshot QR как доказательство прохода;
- выдавать scanner response с паспортом, адресом, телефоном, документами или СНИЛС.
