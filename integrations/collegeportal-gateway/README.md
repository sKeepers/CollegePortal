# CollegePortal Gateway

CollegePortal Gateway is a modular Windows service for protected integrations. It replaces the FIS-specific Gateway Agent naming while preserving the FIS adapter and old `/fis/test/...` routes as deprecated aliases.

## Compatibility

- Windows 7
- .NET Framework 4.8
- Windows Service: `CollegePortalGateway`
- Install path: `C:\CollegePortalGateway`
- No Docker, IIS or modern .NET runtime required

## Projects

```text
src/CollegePortal.Gateway.Contracts
src/CollegePortal.Gateway.Core
src/CollegePortal.Gateway.Adapters.Fis
src/CollegePortal.Gateway.Host
```

## Endpoints

Common:

- `GET /health`
- `GET /version`
- `GET /capabilities`
- `GET /adapters`
- `GET /adapters/fis/health`
- `POST /diagnostics/run`
- `GET /diagnostics/latest`

FIS:

- `POST /adapters/fis/zkspd/check`
- `POST /adapters/fis/test/dictionaries/list`
- `POST /adapters/fis/test/dictionaries/details`
- `POST /adapters/fis/test/institution/info`
- `POST /adapters/fis/test/check-application`

Disabled until official contract verification:

- `validate`
- `import`
- `import-result`
- production

## Build

Run `build.cmd` on Windows with MSBuild for .NET Framework 4.8. Package with `package.ps1`.
