# CollegePortal Gateway

Windows-служба для защищенных интеграций CollegePortal. Текущий FIS adapter выполняет только TCP-диагностику фиксированного TEST endpoint `10.0.3.1:8383`. SOAP, Import, Validate, Delete и production `:8080` заблокированы до подтверждения официального контракта.

## Совместимость

- Windows 7 SP1 / 10 / 11;
- .NET Framework 4.8;
- Windows PowerShell 2.0 или новее;
- служба `CollegePortalGateway`;
- каталог `C:\CollegePortalGateway`;
- порт `8099`, разрешенный извне только для Linux DEV `192.168.34.104`; loopback используется для local health.

## Сборка

На Windows с Visual Studio Build Tools и .NET Framework 4.8 Developer Pack:

```cmd
build.cmd
powershell.exe -NoProfile -ExecutionPolicy Bypass -File package.ps1
```

Артефакт создается в `releases\collegeportal-gateway-<version>.zip`. ZIP содержит `CollegePortal.Gateway.Host.exe`, installer scripts, `config.example`, `VERSION`, `BUILD_INFO` и внутренний `SHA256SUMS`. Рядом создается SHA-256 самого ZIP.

Все PowerShell-скрипты внутри ZIP совместимы с Windows PowerShell 2.0. CI запускает детерминированный compatibility gate и PSScriptAnalyzer с профилем `desktop-2.0-windows`; проверка повторяется для распакованного ZIP.

На disposable Windows CI runner пакет также проходит фактическую установку службы `CollegePortalGateway`: проверяются этапы `SHA256_VALIDATED`, `SERVICE_INSTALLED` и статус `Running`, после чего тест удаляет только созданные им ресурсы.

## Установка и repair

1. Передать ZIP на ViPNet-ПК только утвержденным способом.
2. Сверить внешний SHA-256 с отдельно полученным ожидаемым значением.
3. Распаковать ZIP в новый каталог.
4. Запустить `install-all.cmd` от имени администратора.
5. Открыть `gateway-menu.cmd` и выполнить health/diagnostics.

До установки можно безопасно проверить пакет и передачу путей без изменения службы, URL ACL, firewall и файлов в `C:\CollegePortalGateway`:

```cmd
install-all.cmd --dry-run
```

Пакет следует распаковывать на локальный диск. UNC-пути отклоняются с понятным stop-gate. Каталоги с пробелами поддерживаются.

При падении EXE выбрать пункт `Диагностика запуска EXE в консоли`. Подробный безопасный stack trace записывается в `C:\CollegePortalGateway\logs\startup.log`; содержимое private config в журнал не записывается.

Installer проверяет пакет до изменения системы, сохраняет рабочий `gateway.private.config`, заменяет только placeholder secret, создает backup бинарников, регистрирует службу под `NetworkService`, ограничивает ACL, URL ACL и firewall. Private secret не выводится.

## Контракты ФИС

`08-download-fis-contract.cmd` делает по одной попытке скачать доступные metadata/XSD только с TEST `:8383`, проверяет HTTP/XML/SHA-256 и сохраняет их в private storage. `09-import-fis-contract.cmd` проверяет manifest и формирует private-анализ. Эти действия не подтверждают официальный статус автоматически и не разрешают live XML-over-HTTP вызовы.

Если служба, порт, TEST endpoint, official XSD/spec или authentication не подтверждены, дальнейшая работа прекращается по stop-gate.
