# Поток обмена ФИС через CollegePortal Gateway

## Целевая цепочка

```text
CollegePortal Portal
  -> Integration Hub client
  -> CollegePortal Gateway (192.168.34.223:8099)
  -> ViPNet / ЗКСПД
  -> ФИС TEST (10.0.3.1:8383)
```

Production endpoint `10.0.3.1:8080` в GIA-001 запрещен и не проверяется.

## Границы ответственности

- Portal хранит бизнес-пакеты, RBAC, diagnostics и обезличенный communication log.
- Integration Hub подписывает запросы Portal -> Gateway через HMAC, request id, timestamp и nonce.
- Gateway хранит FIS credentials и официальный контракт на ViPNet-ПК.
- FIS adapter формирует SOAP только по активному официальному WSDL/XSD/DISCO.
- ViPNet обеспечивает доступ к закрытому TEST-контуру.

## Фактическое состояние DEV

| Узел | Результат |
|---|---|
| Portal backend | Работает в Docker на `moodle` |
| Portal -> Gateway TCP/8099 | Connection refused 14.07.2026 |
| Gateway health/version | Не получены из-за недоступности службы |
| DEV -> FIS TEST напрямую | Маршрут отсутствует/timeout, прямой путь не используется |
| Gateway -> FIS TEST | Не проверен в текущем запуске |
| WSDL/DISCO в DEV | Отсутствуют |
| Официальный XSD | Загружен локально, SHA-256 зафиксирован |

## Безопасность

- Shared secret и FIS credentials не записываются в Git и communication log.
- Portal передает Gateway только технический request id и разрешенную команду.
- SOAP payload не сохраняется в `fis_communication_logs`.
- Production hard-disabled независимо от UI.
- Ошибка диагностики не должна включать login, password, token или HMAC signature.

## Stop-gate

Первый read-only SOAP request не выполнен: Gateway не слушает `192.168.34.223:8099`, а официальный WSDL/DISCO отсутствует на DEV. Следующее действие выполняется на ViPNet-ПК: запустить/проверить службу Gateway, скачать официальный TEST contract скриптом `08-download-fis-contract.cmd`, импортировать его скриптом `09-import-fis-contract.cmd` и безопасно передать manifest/WSDL/DISCO на DEV для parser verification.
