# FIS XML-over-HTTP Protocol

GIA-003.3 records the official protocol correction for FIS GIA and Admissions integration.

## Confirmed

- FIS import service is XML-over-HTTP, not SOAP.
- Request transport is HTTP POST with an XML body.
- XSD is the authoritative contract for XML package structure.
- SOAP envelope, SOAPAction, WSDL binding, service and port are not used by CollegePortal runtime transport.
- TEST endpoint: `http://10.0.3.1:8383/api/import/importservice.svc`.
- Production endpoint `:8080` is blocked and must not be used during GIA-003.

## Runtime Rules

- Gateway and backend allow only the fixed TEST endpoint.
- Mutating operations remain disabled by default.
- XML is parsed with external network entities disabled.
- SOAP envelopes and DOCTYPE declarations are rejected.
- Communication logs store request id, method, duration, status and HTTP code, but no raw XML, credentials or personal data.

## Current Stop-Gate

The first live read-only call is blocked until the private official materials confirm:

- exact XML root and namespace for `GetTestDictionariesList`;
- request and response shape;
- content type and encoding;
- authentication location: transport headers or payload elements;
- test credentials loaded from private config only.

No Import, Validate or Delete operation is allowed in GIA-003.
