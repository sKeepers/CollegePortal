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
