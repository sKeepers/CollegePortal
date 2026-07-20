# FIS WSDL Analysis

## GIA-003.3 Result

Official support clarified that the FIS GIA and Admissions exchange does not use a SOAP/WSDL runtime contract.

Therefore:

- WSDL binding, service, port and SOAPAction are not required.
- Absence of WSDL/DISCO is not a blocker by itself.
- Active contract evidence is the official XSD/specification for XML-over-HTTP.
- Runtime transport must send HTTP POST with XML body to TEST `http://10.0.3.1:8383/api/import/importservice.svc`.

This document is retained only to explain why the previous WSDL stop-gate was closed as an incorrect assumption.
