@echo off
set ROOT=%~dp0
set MSBUILD=msbuild
where msbuild >nul 2>&1 || set MSBUILD=%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\MSBuild.exe
if not exist "%MSBUILD%" (
  echo MSBuild not found. Install Visual Studio Build Tools or .NET Framework build tools.
  exit /b 1
)
set BUILD_ROOT=%TEMP%\collegeportal-gateway-build
set BUILD_OUT=%BUILD_ROOT%\out\
set BUILD_OBJ=%BUILD_ROOT%\obj\
if exist "%BUILD_ROOT%" rmdir /s /q "%BUILD_ROOT%"
mkdir "%BUILD_OUT%" "%BUILD_OBJ%"
if not exist "%ROOT%bin" mkdir "%ROOT%bin"
"%MSBUILD%" "%ROOT%src\CollegePortal.Gateway.Host\CollegePortal.Gateway.Host.csproj" /t:Rebuild /p:Configuration=Release /p:OutputPath=%BUILD_OUT% /p:BaseIntermediateOutputPath=%BUILD_OBJ% /nologo /v:m || exit /b 1
copy /Y "%BUILD_OUT%CollegePortal.Gateway.Host.exe" "%ROOT%bin\CollegePortal.Gateway.Host.exe" >nul || exit /b 1
echo Built %ROOT%bin\CollegePortal.Gateway.Host.exe
