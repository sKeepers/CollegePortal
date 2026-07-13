# ФИС TEST Environment

## Endpoint

```text
http://10.0.3.1:8383/api/import/importservice.svc
```

Адрес доступен только в защищенной сети ФЦТ/ЗКСПД.

## Диагностика

```bash
php artisan fis:connection-check --environment=test
```

Команда проверяет только TCP-доступность и не отправляет SOAP payload или credentials.

## Если endpoint недоступен

Это ожидаемо вне защищенной сети. Статус разработки: `READY FOR TEST CREDENTIALS / READY FOR ZKSPD ACCESS`.

## Credentials

Не передавать credentials в чат и не хранить в Git. Использовать secret mount или encrypted runtime configuration.


## DEV check 13.07.2026

`php artisan fis:connection-check --environment=test` returned:

- endpoint: `http://10.0.3.1:8383/api/import/importservice.svc`
- reachable: `false`
- latency: about `5005 ms`
- error: `Connection timed out`

Conclusion: DEV is not inside the required protected FCT/ZKSPD network path. Continue with mock transport until network route and TEST credentials are provided.


## FIS-API-001.1 host/container route check

Host check:

- `ip route get 10.0.3.1` routes via `192.168.34.1 dev eth0 src 192.168.34.104`.
- `nc -vz -w 5 10.0.3.1 8383`: timed out.
- `curl --connect-timeout 5 http://10.0.3.1:8383/api/import/importservice.svc`: timeout.

Backend container check:

- `ip` and `nc` are not installed in the container.
- `curl` to TEST endpoint also timed out.

Conclusion: current DEV host/container do not have working ZKSPD access to FIS TEST.
