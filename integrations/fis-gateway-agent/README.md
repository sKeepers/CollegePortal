# CollegePortal FIS Gateway Agent

Windows 7 compatible ViPNet workstation adapter for read-only FIS TEST diagnostics.

- Target runtime: .NET Framework 4.8.
- Hosting: self-hosted HttpListener, no IIS and no Docker.
- Safe operations only: health, version, ZKSPD check, test dictionaries, institution info and test application check.
- Dangerous operations are disabled until the official application XSD and authentication contract are confirmed.
- Production endpoint 10.0.3.1:8080 is never configured by this package.

Build on Windows with Visual Studio Build Tools/MSBuild for .NET Framework 4.8:

```cmd
build.cmd
package.cmd
```
