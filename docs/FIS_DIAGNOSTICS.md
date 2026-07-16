# Диагностика ФИС

## Назначение

Маршрут `/fis/diagnostics` показывает evidence-only состояние цепочки Portal → Gateway → ViPNet → FIS TEST. Диагностика не вызывает SOAP, Import, Validate, Delete и production.

## API

```text
GET  /api/fis/diagnostics
POST /api/fis/diagnostics/run
GET  /api/fis/communication-logs
```

Требуется permission `fis.outbound.view`.

- `GET` возвращает configuration/registry snapshot без network probe.
- `POST .../run` проверяет TCP Gateway, его public endpoints при открытом порте и прямую TCP-доступность TEST.
- Protected FIS adapter health вызывается только при TEST-only Gateway configuration и HMAC.
- Read-only SOAP требует отдельного будущего one-time permit; текущий endpoint его не выполняет.

## Набор проверок

- доступность CollegePortal backend;
- target, host и TCP `8099` Gateway;
- Windows-service state: `running` только если `/health` успешен, иначе `unknown`;
- `/health`, `/version`, `/adapters` без redirects;
- protected `/adapters/fis/health`;
- ViPNet/ZKSPD только по signed Gateway evidence;
- прямой DEV→TEST TCP как диагностический факт, не рабочий transport;
- private registry, manifest и SHA-256;
- количество WSDL/XSD/DISCO;
- parser summary: bindings, ports, SOAP versions, actions и operations;
- approval contract/auth/read-only operation;
- strict stop-gate blockers.

## Интерпретация network evidence

| Код | Значение |
|---|---|
| `tcp_refused` | получен TCP reject/RST; remote root cause не определен |
| `tcp_timeout` | endpoint не ответил до timeout |
| `tcp_unreachable` | соединение не установлено по иной сетевой причине |
| `gateway_health_unconfirmed` | процесс Gateway не подтвержден `/health` |
| `gateway_fis_adapter_unconfirmed` | signed adapter evidence отсутствует |

Диагностика не утверждает «служба остановлена» по одному `connection refused`.

## Contract verification

Файл считается обнаруженным, но не обязательно активным. Bundle verified требует:

1. WSDL, XSD и DISCO;
2. manifest SHA для каждого contract artifact;
3. активные approved paths;
4. WSDL bindings, ports, operations и SOAP actions;
5. явный approval `FIS_API_CONTRACT_VERIFIED=true` после ручной проверки;
6. отдельно подтвержденную authentication и allowlist read-only operations.

Наличие одного XSD не снимает SOAP stop-gate.

## FIS Communication Log

Хранятся:

- timestamp;
- method/path;
- request id;
- duration;
- status и HTTP code;
- SOAP Fault code;
- SHA-256 fault text без самого текста;
- технический error code;
- allowlisted metadata.

Не хранятся payload, raw SOAP body, SOAP Fault text, response body, ПДн, credentials, token, shared secret и HMAC signature.

## CLI helpers

```bash
scripts/fis/check-gateway-chain.sh
scripts/fis/check-zkspd-access.sh
```

Оба скрипта выполняют только status/TCP checks и не сохраняют response bodies. `check-gateway-chain.sh` не использует HMAC и рассматривает `401/403` protected endpoint только как признак доступного HTTP route.

## Production

Диагностика всегда возвращает `production_enabled=false`. Порт `:8080` не используется.

## Windows evidence GIA-002

Gateway package содержит `04-health.cmd` и `07-collect-diagnostics.cmd`. Локальный отчет включает service config/state, port owner, URL ACL, firewall allowlist, route к TEST, binary SHA и private config ACL, но не содержит config values или contract bodies. Portal продолжает показывать Windows service как `unknown`, пока `/health` не подтвержден фактическим HTTP-ответом.

## GIA-003 diagnostics snapshot 16.07.2026

Фактическое состояние:

- Gateway `/health`: ok, `0.2.10-dev`.
- Gateway `/version`: ok, `0.2.10-dev`.
- Gateway `/adapters`: FIS enabled, TEST, production disabled, dangerous operations disabled.
- TEST metadata `10.0.3.1:8383`: WSDL/XSD/DISCO downloaded through ViPNet-PC.
- Contract parser: 17 operations in `portType`.
- Blocking parser result: binding count `0`, SOAPAction count `0`, service port count `0`.

`/fis/diagnostics` должен продолжать показывать stop-gate до появления verified bundle с binding/action/authentication и отдельного one-time permit на read-only вызов.
