# FIS TEST Runbook

## Current Mode

GIA-003 uses XML-over-HTTP diagnostics only. Do not execute Import, Validate or Delete.

## Before A Live Read-only Call

1. Confirm the Gateway service is running on the ViPNet machine.
2. Confirm TEST endpoint `10.0.3.1:8383` is reachable from the Gateway host.
3. Load official XSD/spec files into private storage and verify SHA-256 manifest.
4. Confirm the exact XML for `GetTestDictionariesList` from official materials.
5. Confirm authentication fields or headers from official materials.
6. Confirm `/api/fis/diagnostics` reports only the one-time permit as remaining blocker.

## Forbidden

- Production endpoint `:8080`.
- Real personal data in test payloads.
- SOAP envelope or SOAPAction.
- Raw XML or credentials in logs.
