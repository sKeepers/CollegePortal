# CollegePortal Gateway

CollegePortal Gateway - модульная Windows-служба для защищенных интеграций CollegePortal.

Текущий пакет `0.2.1-dev` предназначен для установки на отдельный ViPNet-ПК и содержит FIS-adapter для тестового контура ФИС ГИА и Приема. Продуктивный endpoint ФИС в пакете не включается и не должен использоваться без отдельного решения.

## Совместимость

- Windows 7
- .NET Framework 4.8
- Windows Service: `CollegePortalGateway`
- Каталог установки: `C:\CollegePortalGateway`
- Docker, IIS и современный .NET runtime не требуются

## Быстрая установка на Windows 7

1. Распакуйте ZIP-пакет в любой временный каталог, например `C:\Temp\collegeportal-gateway`.
2. Откройте `cmd.exe` от имени администратора.
3. Перейдите в каталог распакованного пакета.
4. Запустите:

```bat
packaging\windows\install-all.cmd
```

Установщик:

- проверит права администратора и .NET Framework 4.8;
- создаст `C:\CollegePortalGateway`;
- скопирует `CollegePortal.Gateway.Host.exe`;
- создаст `gateway.private.config`, если его еще нет;
- зарегистрирует или обновит службу `CollegePortalGateway`;
- проверит, что служба указывает на `CollegePortal.Gateway.Host.exe`;
- запустит службу;
- проверит `/health`;
- сохранит отчет в `C:\CollegePortalGateway\diagnostics\install-report.txt`.
- автоматически создаст случайный `SharedSecret`, если в конфигурации остался placeholder.

Если служба уже была зарегистрирована старым пакетом, установщик обновит `binPath` через `sc config`. Это защищает от ошибки Windows `Системная ошибка 2. Не удается найти указанный файл`, когда служба указывает на старый или отсутствующий EXE.

Для обслуживания можно использовать русское меню:

```bat
packaging\windows\gateway-menu.cmd
```

## Настройка

Основной приватный файл:

```text
C:\CollegePortalGateway\config\gateway.private.config
```

Если файл создается установщиком впервые, `SharedSecret` генерируется автоматически и не выводится в консоль. Если используется заранее подготовленный секрет, его можно вручную указать в этом файле до подключения CollegePortal.

Запрещено хранить в репозитории и ZIP-пакете:

- реальные секреты;
- приватные ключи;
- рабочие конфиги;
- WSDL/XSD с ограничениями распространения;
- логи с персональными данными.

## Диагностика

Проверить состояние службы:

```bat
sc query CollegePortalGateway
sc qc CollegePortalGateway
```

Проверить HTTP health:

```bat
packaging\windows\04-health.cmd
```

Собрать диагностический файл:

```bat
packaging\windows\07-collect-diagnostics.cmd
```

Диагностика сохраняется в:

```text
C:\CollegePortalGateway\diagnostics\gateway-diagnostics.txt
```

## Проекты

```text
src/CollegePortal.Gateway.Contracts
src/CollegePortal.Gateway.Core
src/CollegePortal.Gateway.Adapters.Fis
src/CollegePortal.Gateway.Host
```

## Endpoints

Common:

- `GET /health`
- `GET /version`
- `GET /capabilities`
- `GET /adapters`
- `GET /adapters/fis/health`
- `POST /diagnostics/run`
- `GET /diagnostics/latest`

FIS:

- `POST /adapters/fis/zkspd/check`
- `POST /adapters/fis/test/dictionaries/list`
- `POST /adapters/fis/test/dictionaries/details`
- `POST /adapters/fis/test/institution/info`
- `POST /adapters/fis/test/check-application`

Отключены до подтверждения официального контракта:

- `validate`
- `import`
- `import-result`
- production

## Сборка

Собрать на Windows:

```bat
build.cmd
```

Сформировать ZIP:

```bat
package.cmd
```
