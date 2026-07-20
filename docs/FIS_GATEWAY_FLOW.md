# FIS Gateway Flow

CollegePortal routes FIS TEST traffic through the CollegePortal Gateway on the ViPNet-connected machine.

```text
CollegePortal
  -> signed internal Gateway API
  -> CollegePortal Gateway
  -> ViPNet/ZKSPD route
  -> FIS TEST 10.0.3.1:8383 XML-over-HTTP endpoint
```

## Current Runtime Rules

- Portal-to-Gateway requests are signed with HMAC.
- Gateway exposes public health/version/adapters endpoints and protected FIS adapter endpoints.
- Gateway FIS adapter blocks Import/Validate/Delete and production endpoint usage.
- Gateway reports XML-over-HTTP stop-gates for read-only methods until official XSD/auth/request evidence is available.

No raw XML, credentials or personal data are written to Gateway audit logs.
