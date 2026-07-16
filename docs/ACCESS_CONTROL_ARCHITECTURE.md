# Access Control Foundation

ACCESS-001 вводит foundation модуля «Проходная» без подключения турникетов и без биометрии.

## Цели MVP

- динамический QR-пропуск с TTL 30 секунд;
- USB/беспроводной 2D-сканер как HID-клавиатура;
- мобильная камера как сканер;
- журнал проходов;
- точки доступа и устройства;
- RBAC для оператора и администратора проходной;
- replay protection и отказ от персональных данных в QR.

## Компоненты

- `access_points` — физические точки прохода;
- `access_devices` — HID-сканер, мобильная камера или ручной режим;
- `access_pass_tokens` — hash-only registry динамических QR;
- `access_events` — события allowed/denied;
- `access_sessions` — пары вход/выход;
- `access_rules` — будущие правила доступа;
- `access_operator_shifts` — смены операторов;
- `access_denials` — нормализованные причины отказов;
- `access_audit_events` — технический аудит без raw token.

## Поток

1. Пользователь открывает `/access/pass`.
2. Backend выпускает короткоживущий signed token.
3. QR содержит только token, без ФИО, группы, телефона или документов.
4. Оператор сканирует QR на `/access/checkpoint`, `/access/gate` или `/access/mobile-scanner`.
5. Backend проверяет подпись, TTL, hash, nonce, replay и статус.
6. Результат записывается в `access_events` и отображается оператору.

## Совместимость

Старые статические `digital_identities.token` остаются fallback-режимом для уже выпущенных пропусков. Основной режим ACCESS-001 — динамический QR `CP2:<payload>.<signature>`.

## Attendance seam

`AccessAttendanceBridge` добавлен как integration seam. В ACCESS-001 используется `NullAccessAttendanceBridge`; автоматическая запись в журнал занятий и attendance analytics остается для следующих задач.
