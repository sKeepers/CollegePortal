# Установка CollegePortal Gateway на Windows

## Целевое окружение

- ViPNet-ПК: `192.168.34.223`, Windows 7 SP1;
- .NET Framework 4.8 и PowerShell 3+;
- каталог: `C:\CollegePortalGateway`;
- служба: `CollegePortalGateway`;
- executable: `C:\CollegePortalGateway\bin\CollegePortal.Gateway.Host.exe`;
- listener: TCP `8099`;
- внешний разрешенный Portal IP: только `192.168.34.104`; loopback разрешен для локальной health-проверки;
- FIS TEST: только `10.0.3.1:8383`;
- FIS production `:8080`: запрещен.

## Безопасная передача

Установка выполняется только интерактивно через подтвержденную RDP-сессию или ручным запуском оператором на ViPNet-ПК. Пароли Windows, HMAC secret и FIS credentials не передаются через Git, Issues, логи или отчет задачи.

ZIP и его ожидаемый SHA-256 должны передаваться раздельно. Перед распаковкой оператор сверяет:

```cmd
certutil -hashfile collegeportal-gateway-0.2.3-dev.zip SHA256
```

## Установка или repair

Перед установкой выполнить безопасную предварительную проверку из распакованного пакета:

```cmd
install-all.cmd --dry-run
```

Dry-run проверяет нормализацию `PackageRoot`, обязательные файлы, `SHA256SUMS`, Windows и .NET Framework, но не создает каталог установки, службу, URL ACL или firewall rule. Пакет должен находиться на локальном диске; UNC-пути не поддерживаются. Пути с пробелами и корень диска обрабатываются без завершающей кавычки в значении `PackageRoot`.

Из распакованного пакета запустить от имени администратора:

```cmd
install-all.cmd
```

Installer выполняет:

1. проверку Windows, прав администратора, .NET Framework 4.8, EXE и `SHA256SUMS`;
2. остановку существующей службы и backup предыдущих бинарников;
3. сохранение существующего private config;
4. генерацию случайного HMAC secret только вместо placeholder, без вывода значения;
5. проверку fixed TEST endpoint и выключенных dangerous/production flags;
6. ACL для `NetworkService`, Administrators и SYSTEM;
7. URL ACL `http://+:8099/`;
8. firewall rule TCP `8099` только от `192.168.34.104`;
9. создание или repair службы и обновление `binPath`;
10. запуск и локальные `/health`, `/version`, `/adapters`, signed FIS adapter health;
11. русский отчет `C:\CollegePortalGateway\diagnostics\installation-report.txt`.

Нельзя отключать Windows Firewall, ViPNet или защитные службы. Если настройка URL ACL/firewall не проходит, installer прекращает работу и запрещает переход к контрактам/SOAP.

## Ручная проверка после установки

В `gateway-menu.cmd` выполнить health и сбор диагностики. Дополнительно проверить:

```cmd
sc query CollegePortalGateway
sc qc CollegePortalGateway
netstat -ano | findstr :8099
netsh advfirewall firewall show rule name="CollegePortal Gateway DEV 8099" verbose
```

`07-collect-diagnostics.cmd` собирает service config, recovery, listener, URL ACL, firewall, route к TEST, binary SHA, ACL и ограниченный список ошибок Event Log. Файл проверяется оператором перед передачей.

## Контракт ФИС

Скачивание и импорт выполняются отдельными действиями только после успешного local health:

```cmd
08-download-fis-contract.cmd
09-import-fis-contract.cmd
```

Контракты остаются в `C:\CollegePortalGateway\specs\fis` и не коммитятся. Полученный hash подтверждает целостность скачанных байтов, но официальный статус, authentication и read-only semantics требуют отдельной проверки. До нее SOAP отключен.

## Rollback

`11-rollback.cmd` восстанавливает последний backup бинарников и не изменяет private config. Если служба не запускается, выполнить `07-collect-diagnostics.cmd` и остановиться; не переключать endpoint и не использовать production.
