# TLS-сертификат портала

Инструкция по выпуску, продлению и проверке сертификата для `portal.skki.ru`.

## Что настроено сейчас

- Домен: `portal.skki.ru`, A-запись ведёт на `84.54.208.134` (панель DNS у Mail.ru, не у Beget).
- Сертификат: Let's Encrypt, выпущен 07.08.2026, действует 90 дней.
- Файлы certbot: `/etc/letsencrypt/live/portal.skki.ru/`.
- Рабочие копии для nginx: `/opt/college-portal/certs/fullchain.pem` и `privkey.pem`.
- Способ проверки: HTTP-01 через `--standalone` на порту 80.
- Автопродление: системный таймер `certbot.timer` плюс три хука.

Копии, а не симлинки: контейнер nginx монтирует `/opt/college-portal/certs` только на чтение и не может пройти по ссылке в `/etc/letsencrypt`.

## Автопродление

Продление выполняется само. Certbot запускается таймером дважды в сутки и обновляет сертификат, когда до конца срока остаётся меньше 30 дней.

Порт 80 занят контейнером nginx, поэтому вокруг продления выполняются хуки:

| Хук | Файл | Что делает |
| --- | --- | --- |
| pre | `/etc/letsencrypt/renewal-hooks/pre/collegeportal-stop-nginx.sh` | останавливает nginx, освобождая порт 80 |
| deploy | `/etc/letsencrypt/renewal-hooks/deploy/collegeportal-install-cert.sh` | копирует новые файлы в `/opt/college-portal/certs` |
| post | `/etc/letsencrypt/renewal-hooks/post/collegeportal-start-nginx.sh` | поднимает nginx обратно |

Post-хук выполняется всегда, даже если продление не удалось, — портал не останется лежать.

Портал недоступен около 30 секунд во время продления. Раз в 60 дней ночью это приемлемо; если понадобится продление без простоя, нужно добавить в шаблон nginx отдельный location для `/.well-known/acme-challenge/` и перейти на способ `--webroot`.

Посмотреть, когда следующий запуск:

```bash
systemctl list-timers certbot.timer
```

## Проверка вхолостую

Безопасно и не расходует лимиты Let's Encrypt. Выполняйте после любых изменений в сети или на роутере:

```bash
sudo certbot renew --dry-run
```

Ожидаемый результат — `Congratulations, all simulated renewals succeeded`. Портал при этом кратко перезапустится.

## Ручное продление

Обычно не требуется. Нужно, если автопродление не сработало:

```bash
sudo certbot renew
```

Если до истечения ещё больше 30 дней, certbot ничего не сделает. Принудительно:

```bash
sudo certbot renew --force-renewal
```

Не злоупотребляйте: Let's Encrypt ограничивает выпуск пятью сертификатами на домен в неделю.

## Выпуск с нуля

Нужен при переносе на другой сервер, смене домена или потере `/etc/letsencrypt`.

```bash
cd /opt/college-portal
sudo docker compose -f installer/docker-compose.yml --env-file .env stop nginx

sudo certbot certonly --standalone -d portal.skki.ru \
  --agree-tos -m ВАШ_EMAIL --non-interactive

sudo install -m 644 /etc/letsencrypt/live/portal.skki.ru/fullchain.pem certs/fullchain.pem
sudo install -m 600 /etc/letsencrypt/live/portal.skki.ru/privkey.pem certs/privkey.pem

sudo docker compose -f installer/docker-compose.yml --env-file .env start nginx
```

Для нового домена дополнительно приведите `/opt/college-portal/.env`:

```
APP_URL=https://новый.домен
DOMAIN_OR_IP=новый.домен
HTTPS_MODE=https
```

и пересоздайте nginx:

```bash
sudo docker compose -f installer/docker-compose.yml --env-file .env up -d --force-recreate nginx
```

## Проверка результата

```bash
sudo certbot certificates
openssl x509 -in /opt/college-portal/certs/fullchain.pem -noout -subject -issuer -dates
curl -I https://portal.skki.ru/version.json
```

Снаружи сети, с рабочей машины:

```powershell
Invoke-WebRequest https://portal.skki.ru/version.json -UseBasicParsing
```

Если команда отработала без ключей обхода проверки сертификата, значит цепочка доверия в порядке.

## Что может пойти не так

**`Timeout during connect (likely firewall problem)`** — Let's Encrypt не достучался до порта 80. Проверьте по порядку:

1. Публичная A-запись. Изнутри сети Mikrotik перехватывает DNS и отвечает внутренним адресом, поэтому проверять нужно снаружи:

   ```powershell
   Invoke-RestMethod "https://dns.google/resolve?name=portal.skki.ru&type=A"
   ```

   Должен вернуться `84.54.208.134`.

2. Проброс на Mikrotik:

   ```routeros
   /ip firewall nat print stats where chain=dstnat
   ```

   Счётчики правил портала должны расти при обращении снаружи.

3. **Правила фильтра.** Именно здесь была реальная ошибка при первом выпуске: правила `accept` пропускали трафик на `192.168.34.114` (DEV), тогда как проброс переписывает адрес на `192.168.34.17` (PROD). Проброс срабатывал, а пакеты затем попадали в общий `drop`.

   ```routeros
   /ip firewall filter print where chain=forward
   ```

   В правилах с комментарием `portal.skki.ru` адрес назначения должен совпадать с адресом из правил проброса.

**`Could not bind TCP port 80`** — nginx не был остановлен перед выпуском. Остановите контейнер и повторите.

**Браузер показывает предупреждение после продления** — новые файлы не скопировались в `/opt/college-portal/certs`. Проверьте deploy-хук и даты:

```bash
openssl x509 -in /opt/college-portal/certs/fullchain.pem -noout -dates
openssl x509 -in /etc/letsencrypt/live/portal.skki.ru/fullchain.pem -noout -dates
```

Даты должны совпадать. Если нет — выполните deploy-хук вручную и перезапустите nginx.

## Редирект и заголовки безопасности

Задача `SEC-004`, выполнена в `installer/templates/nginx-release.conf`.

Обращение по HTTP получает `301` на HTTPS. Исключений два, оба намеренные:

- `/.well-known/acme-challenge/` отдаётся по HTTP без перенаправления, иначе сломалось бы продление способом `--webroot`;
- при `HTTPS_MODE=http` редиректа нет вовсе — установка без сертификата продолжает работать как раньше.

Решение принимается по значению `HTTPS_MODE` из `.env`: `http` означает установку без TLS, любое другое значение (`existing-cert`, `letsencrypt`, `self-signed`, `https`) включает редирект. Нестандартный `HTTPS_PORT` подставляется в адрес перенаправления.

Заголовки ответа:

| Заголовок | Значение | Примечание |
| --- | --- | --- |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | только по HTTPS и только при настроенном TLS; без `preload` — он необратим |
| `Content-Security-Policy` | `default-src 'self'` с уточнениями | `style-src` допускает `'unsafe-inline'`: Quasar пишет стили в разметку |
| `X-Content-Type-Options` | `nosniff` | |
| `X-Frame-Options` | `SAMEORIGIN` | дублирует `frame-ancestors` для старых браузеров |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | |
| `Permissions-Policy` | камера разрешена себе, остальное запрещено | `camera=(self)` обязателен: без него не работает сканер проходной |

TLS ограничен версиями 1.2 и 1.3, набор шифров задан явно, session tickets выключены, версия nginx скрыта.

CSP проверена по релизной сборке фронтенда: во `frontend/dist/index.html` нет ни встроенных `<script>`, ни обработчиков в разметке, все ресурсы загружаются с того же origin. Внешних доменов, шрифтов с CDN, web worker'ов и `eval` в бандле нет. Если появится что-то из этого списка, CSP придётся расширять вместе с изменением.

## Продление без остановки портала

Сейчас продление идёт способом `--standalone` и останавливает nginx примерно на 30 секунд. Шаблон уже готов к переходу на `--webroot`: каталог `/opt/college-portal/acme` смонтирован в контейнер как `/var/www/certbot`, а запросы проверки не перенаправляются.

Переход, когда простой станет нежелателен:

```bash
sudo certbot certonly --webroot -w /opt/college-portal/acme -d portal.skki.ru --cert-name portal.skki.ru
sudo rm /etc/letsencrypt/renewal-hooks/pre/collegeportal-stop-nginx.sh
sudo rm /etc/letsencrypt/renewal-hooks/post/collegeportal-start-nginx.sh
sudo certbot renew --dry-run
```

Deploy-хук остаётся: он по-прежнему копирует новые файлы в `/opt/college-portal/certs`. Без pre- и post-хуков nginx не перезапускается и продолжает держать в памяти старый сертификат, поэтому в конец deploy-хука обязательно добавить перечитывание конфигурации:

```bash
docker compose -f /opt/college-portal/installer/docker-compose.yml \
  --env-file /opt/college-portal/.env exec -T nginx nginx -s reload
```
