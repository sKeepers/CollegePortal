# FIS SOAP Contract

This document is retained as a deprecated historical note.

Official support clarified that FIS GIA and Admissions import is not exposed as a SOAP/WSDL service for CollegePortal integration. Runtime code must not build SOAP envelopes, set SOAPAction, or depend on WSDL binding/service/port metadata.

Use [FIS_XML_HTTP_PROTOCOL.md](FIS_XML_HTTP_PROTOCOL.md) as the active protocol document.

## Status

- SOAP model: rejected.
- WSDL/DISCO: optional inventory artifacts only.
- Active contract: official XSD plus XML-over-HTTP instructions.
- TEST endpoint: `http://10.0.3.1:8383/api/import/importservice.svc`.
- PROD endpoint `:8080`: blocked.
