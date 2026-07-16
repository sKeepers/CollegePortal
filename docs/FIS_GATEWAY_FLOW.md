# Поток обмена ФИС через CollegePortal Gateway

## Целевая цепочка

```text
CollegePortal Portal (Linux DEV)
  -> Integration Hub client
  -> CollegePortal Gateway (ViPNet-PC 192.168.34.223:8099)
  -> ViPNet / ЗКСПД
  -> ФИС TEST (10.0.3.1:8383)
```

Production `10.0.3.1:8080` не проверяется и hard-disabled.

## Фактический evidence snapshot

Контрольная проверка: 14.07.2026 17:40 UTC.

| Узел | Наблюдаемый результат |
|---|---|
| SSH / Linux DEV | `moodle`, user `andale`, `/srv/college-dev`, feature branch, clean до изменений |
| CollegePortal backend | `ok`, snapshot сформирован backend-контейнером |
| Portal → Gateway host | ICMP доступен |
| Portal → Gateway TCP `8099` | `tcp_refused`, latency 1 мс |
| Windows-служба Gateway | `unknown`: remote TCP evidence недостаточно |
| `/health`, `/version`, `/adapters` | не запрашивались после закрытого TCP gate |
| `/adapters/fis/health` | заблокирован: Gateway недоступен и HMAC не используется для обхода gate |
| Gateway → ViPNet/ЗКСПД | не подтвержден |
| DEV → FIS TEST `10.0.3.1:8383` | `tcp_timeout`, около 5 с |
| Official WSDL / DISCO | отсутствуют |
| Official XSD | найден, manifest SHA совпадает |

`tcp_refused` не доказывает конкретную причину. Без безопасного локального доступа к Windows нельзя различить остановленную службу, bind failure, active reject firewall или падение процесса.

## Границы ответственности

- Portal хранит RBAC, business state и обезличенный communication log.
- Portal выполняет публичные Gateway probes только после успешного TCP-check.
- Integration Hub подписывает protected requests HMAC request-id/timestamp/nonce.
- Gateway хранит credentials и выполняет network/SOAP действия внутри ViPNet-контура.
- ViPNet-PC configuration изменяется только оператором через подтвержденный безопасный канал.
- Официальный contract bundle хранится в private storage и проверяется по SHA-256.

## Stop-gate

Первый read-only SOAP call не выполнялся. Одновременно действуют блокеры:

- `tcp_refused` для Gateway `8099`;
- `tcp_timeout` для прямого DEV→TEST пути;
- `official_wsdl_missing`;
- `official_disco_missing`;
- `active_xsd_missing` до approval полного bundle;
- `fis_authentication_unknown`;
- `read_only_operation_unconfirmed`.

Следующий безопасный шаг: на ViPNet-PC вручную собрать evidence `sc query`, local `127.0.0.1:8099/health`, bind/firewall/event log без изменения configuration, затем получить WSDL/DISCO официальным способом. Одна контролируемая read-only попытка разрешается только после закрытия всех gates.

## GIA-002 package preparation

Подготовлен воспроизводимый Gateway package `0.2.1-dev`: Release EXE, внутренний manifest SHA-256, русский install/repair flow, local HMAC health, безопасная диагностика и private contract intake. Пакет не содержит secrets, private config, WSDL/XSD/DISCO, logs или diagnostics.

Установка на `192.168.34.223` не выполнялась автоматически: подтвержденной интерактивной RDP-сессии нет. Поэтому наблюдаемый network snapshot выше не изменен, а WSDL/DISCO и первый read-only SOAP call остаются заблокированы.
