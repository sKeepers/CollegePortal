# FIS Gateway Agent

Gateway Agent is intended for an internal workstation/server that has access to the protected FCT/ZKSPD network and can reach `10.0.3.1:8383`.

Portal -> internal HTTPS/API -> Gateway Agent -> FIS TEST endpoint.

Rules:

- Keep FIS credentials only on the gateway host.
- Keep production endpoint disabled.
- Use an allowlist and bearer token or mTLS between Portal and Agent.
- Do not store XML with personal data in Git.
- Do not enable send until official WSDL/XSD/spec version 4.9 is loaded.

## Run for local health/access check

```bash
cp .env.example .env
php -S 127.0.0.1:8099 agent.php
```

Use `/health` and `/zkspd/check` first. `/fis/test/send` is blocked unless official contract support is added and `FIS_AGENT_ENABLE_SEND=true`.
