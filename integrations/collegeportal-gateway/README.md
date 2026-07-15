# CollegePortal Gateway 0.2.1-dev

Windows-служба для защищенных интеграций CollegePortal. Текущий FIS adapter выполняет только TCP-диагностику фиксированного TEST endpoint `10.0.3.1:8383`. SOAP, Import, Validate, Delete и production `:8080` заблокированы до подтверждения официального контракта.

## Совместимость

- Windows 7 SP1 / 10 / 11;
- .NET Framework 4.8;
- Windows PowerShell 3.0 или новее;
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

## Установка и repair

1. Передать ZIP на ViPNet-ПК только утвержденным способом.
2. Сверить внешний SHA-256 с отдельно полученным ожидаемым значением.
3. Распаковать ZIP в новый каталог.
4. Запустить `install-all.cmd` от имени администратора.
5. Открыть `gateway-menu.cmd` и выполнить health/diagnostics.

Installer проверяет пакет до изменения системы, сохраняет рабочий `gateway.private.config`, заменяет только placeholder secret, создает backup бинарников, регистрирует службу под `NetworkService`, ограничивает ACL, URL ACL и firewall. Private secret не выводится.

## Контракты ФИС

`08-download-fis-contract.cmd` делает по одной попытке скачать WSDL/XSD/DISCO только с TEST `:8383`, проверяет HTTP/XML/SHA-256 и сохраняет их в private storage. `09-import-fis-contract.cmd` проверяет manifest и формирует private-анализ. Эти действия не подтверждают официальный статус автоматически и не разрешают SOAP-вызовы.

Если служба, порт, TEST endpoint, WSDL/DISCO или authentication не подтверждены, дальнейшая работа прекращается по stop-gate.
