# Установка CollegePortal Gateway на ViPNet-ПК

## Назначение

Gateway Agent устанавливается на отдельный Windows 7 ViPNet-ПК, который имеет доступ к FIS TEST `10.0.3.1:8383`. CollegePortal обращается только к внутреннему Gateway API.

## Перед установкой

1. Создать отдельную локальную service account, например `cp-fis-gateway`.
2. Распаковать пакет в `C:\CollegePortalGateway`.
3. Создать каталоги `config`, `logs`, `data`.
4. Скопировать `config.example` в `C:\CollegePortalGateway\config\gateway.private.config`.
5. Ограничить ACL каталога config: `SYSTEM` и service account.
6. Сгенерировать длинный `SharedSecret` и записать только в private config и `/srv/college-dev/.secrets/fis-gateway.env`.
7. Указать IP CollegePortal сервера: `AllowedPortalIps=192.168.34.104`.

## Firewall

Входящий порт Gateway открывать только от IP CollegePortal:

```cmd
firewall-install.cmd
```

Команды используют `netsh advfirewall` и не открывают всю подсеть. Исходящий трафик разрешается только к `10.0.3.1:8383`. Порт `8080` не открывается.

Не отключать Windows Firewall, ViPNet firewall и защитные компоненты ViPNet.

## Установка службы

```cmd
install-service.cmd
net start CollegePortalGateway
check.cmd
```

Для первичной диагностики можно запустить console mode:

```cmd
start.cmd
```

## Проверка

1. `GET /health` с самого ViPNet-ПК.
2. `POST /zkspd/check` через CollegePortal `/fis`.
3. `POST /fis/test/dictionaries/list` через CollegePortal.
4. Убедиться, что Validate/Import возвращают `official_application_xsd_missing` / `operation_disabled`.

## Настройка CollegePortal

Файл `/srv/college-dev/.secrets/fis-gateway.env`:

```env
FIS_API_TRANSPORT=gateway
FIS_GATEWAY_ENABLED=true
FIS_GATEWAY_URL=http://192.168.34.223:8099
FIS_GATEWAY_SHARED_SECRET=<generated-secret>
FIS_GATEWAY_ALLOWED_ENVIRONMENT=test
FIS_GATEWAY_CONNECT_TIMEOUT=5
FIS_GATEWAY_REQUEST_TIMEOUT=30
```

Права:

```bash
chmod 700 /srv/college-dev/.secrets
chmod 600 /srv/college-dev/.secrets/fis-gateway.env
```

## Rollback

1. `net stop CollegePortalGateway`.
2. `uninstall-service.cmd`.
3. `firewall-remove.cmd`.
4. Сохранить `logsudit.log` и private config в backup.
5. Удалять каталог агента только после backup private config.

## Ограничения FIS-GATEWAY-001

DEV/Linux не содержит MSBuild/.NET Framework 4.8, поэтому сборка `.exe` и реальный запуск на Windows 7 не проверены в этой задаче. Пакет содержит исходники, scripts и инструкции для сборки на Windows.

## Migration Note

CollegePortal Gateway has been generalized into CollegePortal Gateway. New installations use `C:\CollegePortalGateway`, service name `CollegePortalGateway`, and package name `collegeportal-gateway-<version>.zip`. Old `C:\CollegePortalGateway` instructions are retained only for historical migration review.
