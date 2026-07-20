# FIS Diagnostics

`/api/fis/diagnostics` reports FIS integration readiness without performing mutating FIS operations.

## Protocol

Diagnostics now report:

- `protocol = xml_over_http`;
- SOAP as `not_applicable`;
- XSD as the required contract artifact;
- production guard status;
- Gateway and ViPNet/TEST reachability evidence;
- read-only stop-gates.

## Stop-Gates

The read-only block remains closed when any of these are missing:

- approved official XSD bundle;
- confirmed authentication;
- confirmed read-only XML request;
- signed Gateway configuration;
- Gateway FIS adapter health;
- one-time operator permit for the live TEST call.

Diagnostics never store raw XML, credentials, SOAP body, tokens or personal data.
