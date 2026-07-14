# Установка и обслуживание CollegePortal Gateway

Документ описывает установку CollegePortal Gateway на ViPNet-ПК Windows 7 SP1 с .NET Framework 4.8.

## Назначение

Gateway устанавливается на защищенный узел, который имеет доступ к тестовому контуру ФИС ГИА и Приема `10.0.3.1:8383`. CollegePortal обращается к Gateway по внутреннему HTTP API. Продуктивный endpoint ФИС `:8080` в этом пакете не используется.

## Быстрая установка

1. Распаковать `collegeportal-gateway-0.2.1-dev.zip` на ViPNet-ПК.
2. Открыть `cmd.exe` от имени администратора.
3. Выполнить `packaging\windows\install-all.cmd`.
4. Проверить `C:\CollegePortalGateway\diagnostics\install.log`.
5. Заменить `SharedSecret=CHANGE_ME...` в `C:\CollegePortalGateway\config\gateway.private.config` перед подключением CollegePortal.

## Что делает install-all.cmd

- проверяет права администратора;
- проверяет Windows 7 / Windows Server 2008 R2 family;
- проверяет .NET Framework 4.8;
- проверяет наличие EXE и `SHA256SUMS`;
- создает каталоги `bin`, `config`, `logs`, `backup`, `cache`, `diagnostics`, `specs`, `updates`;
- копирует `CollegePortal.Gateway.Host.exe`;
- создает `gateway.private.config` из `config.example`, если файла еще нет;
- не перезаписывает существующий private config;
- настраивает firewall на TCP 8099 только для `192.168.34.104`;
- регистрирует или обновляет службу `CollegePortalGateway`;
- запускает службу;
- проверяет `/health`, `/version`, `/adapters`, `/adapters/fis/health`;
- собирает диагностику.

## Причина System Error 2

Ошибка `Системная ошибка 2. Не удается найти указанный файл` возникала, когда Windows-служба уже была зарегистрирована и указывала на старый или отсутствующий executable. Новый установщик всегда обновляет `binPath` службы через `sc config` и проверяет, что путь содержит `CollegePortal.Gateway.Host.exe`.

## Обновление

Для обновления распаковать новый ZIP и запустить `packaging\windows\05-update.cmd` или пункт меню `2 Обновить Gateway`. Private config сохраняется.

## Удаление

Запустить `packaging\windows\10-uninstall.cmd`. Скрипт удаляет службу, но не удаляет `C:\CollegePortalGateway`, чтобы не потерять конфиги, логи и диагностику.

## Диагностика

Запустить `packaging\windows\07-collect-diagnostics.cmd`. Отчет будет сохранен в `C:\CollegePortalGateway\diagnostics\gateway-diagnostics.txt`. Перед отправкой отчета необходимо проверить, что в нем нет секретов.

## WSDL/XSD ФИС

Для загрузки контракта запустить `packaging\windows\08-download-fis-contract.cmd`. Файлы сохраняются в `C:\CollegePortalGateway\specs\fis\discovered`.

Загружаются:

- `?singleWsdl`;
- `?wsdl`;
- `?xsd=xsd0`;
- `?xsd=xsd1`;
- `?disco`.

После загрузки выполнить `packaging\windows\09-import-fis-contract.cmd`, чтобы создать manifest, SHA256 и список операций.

## Firewall

Установщик создает правило `CollegePortal Gateway`, разрешающее TCP 8099 только для сервера `192.168.34.104`. Если локальная политика Windows 7 не поддерживает `netsh advfirewall`, правило нужно проверить вручную.

## Русское меню

Запустить `packaging\windows\gateway-menu.cmd` для обслуживания Gateway через меню.