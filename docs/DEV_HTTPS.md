# DEV HTTPS для CollegePortal

## Назначение

DEV HTTPS нужен для проверки функций браузера, которые доступны только в secure context. Главный сценарий - мобильный сканер проходной `/access/mobile-scanner`, которому нужен доступ к камере телефона.

PROD не затрагивается. HTTP-порты DEV остаются доступными:

- frontend diagnostics: `http://192.168.34.114:5174`
- backend/API diagnostics: `http://192.168.34.114:8001`
- PostgreSQL DEV: `192.168.34.114:5433`

Основной HTTPS endpoint DEV:

- `https://192.168.34.114:5443`
- `https://college-dev.local:5443`, если имя прописано в DNS или `hosts`

## Схема

Используется отдельный контейнер `college_dev_https_proxy` на базе Nginx.

Проксирование:

- `/` -> Vue/Vite dev server `frontend:5173`
- `/api` -> существующий Laravel/Nginx DEV backend `nginx:80`
- `/storage` -> существующий Laravel/Nginx DEV backend `nginx:80`
- WebSocket/HMR -> `frontend:5173`

Существующие контейнеры и HTTP-порты не отключаются.

## Сертификаты

Для DEV используется локальный CA, созданный скриптом:

```bash
cd /srv/college-dev
./scripts/dev-https/create-dev-ca.sh
```

Скрипт создает:

- `infra/dev-https/certs/college-dev-root-ca.crt` - корневой сертификат CA для установки на устройства;
- `infra/dev-https/certs/college-dev-root-ca.key` - приватный ключ CA;
- `infra/dev-https/certs/college-dev.local.crt` - сертификат сервера;
- `infra/dev-https/certs/college-dev.local.key` - приватный ключ сервера.

Папка `infra/dev-https/certs/` исключена из git. Приватные ключи нельзя коммитить и нельзя отправлять в переписке.

## Запуск HTTPS proxy

```bash
cd /srv/college-dev
./scripts/dev-https/create-dev-ca.sh
docker compose up -d https-proxy
```

Проверка:

```bash
curl -k -I https://192.168.34.114:5443
curl -k -I https://192.168.34.114:5443/access/mobile-scanner
curl -k -I https://192.168.34.114:5443/version.json
curl -k -I https://192.168.34.114:5443/api/settings/public
```

После установки CA на устройство `-k` в `curl` не нужен.

## Настройка имени college-dev.local

Если локального DNS нет, добавьте запись на клиентском устройстве или роутере:

```text
192.168.34.114 college-dev.local
```

На Windows файл hosts находится здесь:

```text
C:\Windows\System32\drivers\etc\hosts
```

Открывать редактор нужно от имени администратора.

## Установка CA на Windows

1. Скопируйте файл `/srv/college-dev/infra/dev-https/certs/college-dev-root-ca.crt` на Windows.
2. Дважды нажмите на файл.
3. Выберите `Установить сертификат`.
4. Выберите `Локальный компьютер`.
5. Поместите сертификат в `Доверенные корневые центры сертификации`.
6. Подтвердите установку.
7. Перезапустите браузер.
8. Откройте `https://192.168.34.114:5443`.

## Установка CA на Android

Названия пунктов зависят от версии Android и оболочки производителя.

1. Скопируйте `college-dev-root-ca.crt` на телефон.
2. Откройте `Настройки`.
3. Найдите раздел `Безопасность` -> `Шифрование и учетные данные` -> `Установить сертификат`.
4. Выберите `Сертификат CA`.
5. Установите файл `college-dev-root-ca.crt`.
6. Если Android попросит PIN/пароль экрана, задайте или подтвердите его.
7. Откройте Chrome/Edge: `https://192.168.34.114:5443/access/mobile-scanner`.
8. Разрешите доступ к камере.

Важно: некоторые приложения на Android не доверяют пользовательским CA. Для проверки используйте Chrome или Edge.

## Установка CA на iPhone/iPad

1. Передайте `college-dev-root-ca.crt` на устройство через AirDrop, Files, почту или локальный файловый сервер.
2. Откройте файл на устройстве и установите профиль.
3. Перейдите в `Настройки` -> `Основные` -> `VPN и управление устройством` и установите профиль.
4. Перейдите в `Настройки` -> `Основные` -> `Об этом устройстве` -> `Доверие сертификатам`.
5. Включите полное доверие для `CollegePortal DEV Local CA`.
6. Откройте Safari: `https://192.168.34.114:5443/access/mobile-scanner`.
7. Разрешите доступ к камере.

## Проверка secure context

В браузере откройте DevTools Console и выполните:

```js
window.isSecureContext
```

Ожидаемый результат:

```text
true
```

Для мобильного сканера также проверьте:

```js
navigator.mediaDevices && navigator.mediaDevices.getUserMedia
```

Если значение отсутствует, браузер не считает страницу безопасной или не поддерживает камеру.

## Mixed content

Frontend должен обращаться к API через тот же origin: `/api`.

Для HTTPS DEV не задавайте `VITE_API_BASE_URL=http://...` в `frontend/.env`. Рекомендуемое значение:

```env
VITE_API_BASE_URL=
```

или полностью удалить переменную из локального `frontend/.env`.

После изменения `frontend/.env` перезапустите frontend:

```bash
docker compose restart frontend
```

## Удаление CA

Windows:

1. Откройте `certmgr.msc` или `Управление сертификатами компьютера`.
2. Найдите `CollegePortal DEV Local CA` в доверенных корневых центрах.
3. Удалите сертификат.

Android:

1. `Настройки` -> `Безопасность` -> `Шифрование и учетные данные`.
2. Откройте пользовательские сертификаты.
3. Удалите `CollegePortal DEV Local CA`.

IPhone/iPad:

1. `Настройки` -> `Основные` -> `VPN и управление устройством`.
2. Удалите профиль сертификата.
3. Проверьте `Доверие сертификатам`, что CA больше не активен.

## Ограничения браузеров

- Камера на телефоне обычно требует HTTPS.
- Фонарик поддерживается не всеми браузерами и не всеми камерами.
- `BarcodeDetector` доступен не везде; CollegePortal использует локальный fallback `jsQR`.
- Если корпоративная политика запрещает пользовательские CA, используйте USB HID-сканер на `/access/gate` или ручной ввод token.
