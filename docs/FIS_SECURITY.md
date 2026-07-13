# Безопасность официального коннектора ФИС

- Production send выключен по умолчанию.
- `FIS_API_ALLOW_PRODUCTION_SEND=false` по умолчанию.
- Credentials, мастер-ключи и ПКИ не хранятся в Git и не показываются во frontend.
- XML payload хранится в private storage.
- Audit не должен содержать ФИО, паспорт, СНИЛС, XML с ПДн, credentials или ключи.
- XSD validation использует libxml с `LIBXML_NONET` и выключенными external entities.
- Raw request/response можно хранить только в private/encrypted storage или после redaction.
- UAT не использовать для реальных отправок.


## TEST Secret File

When TEST credentials are provided, store them only in:

```text
/srv/college-dev/.secrets/fis-test.env
```

Required permissions:

```bash
chmod 700 /srv/college-dev/.secrets
chmod 600 /srv/college-dev/.secrets/fis-test.env
```

Do not print this file in terminal logs and do not commit it. Authentication format must be taken only from official specification 4.9.
