# FIS API Protocol Analysis

GIA-003.3 replaces the earlier SOAP hypothesis with the official XML-over-HTTP model.

## Official Protocol State

- Protocol: XML-over-HTTP.
- HTTP method: POST.
- Body: XML validated by official XSD.
- SOAP: not used.
- WSDL/DISCO: not required for runtime transport.
- TEST endpoint: `http://10.0.3.1:8383/api/import/importservice.svc`.
- PROD endpoint `:8080`: blocked.

## What Changed

Earlier CollegePortal diagnostics waited for WSDL binding, service/port and SOAPAction. The official support response clarified that these artifacts are not the service contract. The contract is the XML package schema and the HTTP endpoint.

## Current Evidence

The repository contains XML/XSD analysis infrastructure and a TEST-only XML-over-HTTP transport. Private official XSD/spec files are not committed. The live read-only call is not executed unless the private XSD bundle, authentication evidence and exact read-only request are confirmed.

## Stop-Gates

- Official XSD bundle missing or not approved.
- `GetTestDictionariesList` request XML not confirmed.
- Authentication method unknown.
- Gateway TEST route unavailable.
- Any production endpoint usage.
