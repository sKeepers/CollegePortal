# CollegePortal Gateway: локальные правила

- Поддерживать Windows 7 SP1, Windows 10 и Windows 11; текущая целевая платформа — .NET Framework 4.8.
- CMD/BAT, сообщения установщика и документацию писать по-русски с принятой в проекте кодировкой.
- ФИС production `:8080` запрещен. TEST `:8383` использовать только по отдельной задаче.
- Credentials, private config и сертификаты держать вне Git.
- Сохранять HMAC, IP allowlist, nonce/replay protection, idempotency и redacted audit.
- EXE собирать только на Windows build host или GitHub-hosted Windows runner.
- Не разрабатывать и не собирать проект на ViPNet-ПК; туда доставляется только проверенный ZIP Gateway.
- Не выполнять реальные сетевые вызовы ФИС в CI.
