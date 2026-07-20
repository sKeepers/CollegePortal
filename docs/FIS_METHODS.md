# FIS Methods

## Active Protocol

FIS methods are represented as XML-over-HTTP requests, not SOAP operations.

## Confirmed Safe Capability

- `zkspd_check`: Gateway TCP reachability check for TEST `10.0.3.1:8383`; does not call a FIS application method.

## Candidate Read-only Method

- `GetTestDictionariesList`: candidate for the first controlled TEST read-only call.

This method is not enabled for live execution until official XSD/specification confirms exact XML root, namespace, request/response shape and authentication.

## Disabled In GIA-003

- Import.
- Validate.
- Delete.
- Production calls.

These operations require a separate controlled task after contract and security review.
