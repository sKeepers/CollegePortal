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

## FIS-GATEWAY-001 Security Notes

Gateway secrets are stored only in `/srv/college-dev/.secrets/collegeportal-gateway.env` and `C:\CollegePortalGateway\config\gateway.private.config`. Do not put shared secrets, FIS credentials, certificates, Authorization/HMAC headers, SOAP bodies or personal data in Git, markdown, database settings or logs.

Gateway controls: IP allowlist, HMAC-SHA256, timestamp, nonce replay protection, body hash, constant-time comparison, request size limit, rate limit and redacted structured audit.
