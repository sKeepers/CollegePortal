@echo off
setlocal EnableExtensions DisableDelayedExpansion
set "ROOT=%~dp0"
set "PROJECT=%ROOT%src\CollegePortal.Gateway.Host\CollegePortal.Gateway.Host.csproj"
set "OUTPUT=%ROOT%artifacts\Release"
set "TEST_PROJECT=%ROOT%tests\CollegePortal.Gateway.Tests.csproj"
set "TEST_OUTPUT=%ROOT%artifacts\Tests"
set "MSBUILD_EXE="

where msbuild.exe >nul 2>&1
if not errorlevel 1 set "MSBUILD_EXE=msbuild.exe"
if not defined MSBUILD_EXE if exist "%ProgramFiles(x86)%\Microsoft Visual Studio\2022\BuildTools\MSBuild\Current\Bin\MSBuild.exe" set "MSBUILD_EXE=%ProgramFiles(x86)%\Microsoft Visual Studio\2022\BuildTools\MSBuild\Current\Bin\MSBuild.exe"
if not defined MSBUILD_EXE if exist "%ProgramFiles%\Microsoft Visual Studio\2022\BuildTools\MSBuild\Current\Bin\MSBuild.exe" set "MSBUILD_EXE=%ProgramFiles%\Microsoft Visual Studio\2022\BuildTools\MSBuild\Current\Bin\MSBuild.exe"
if not defined MSBUILD_EXE if exist "%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\MSBuild.exe" set "MSBUILD_EXE=%WINDIR%\Microsoft.NET\Framework64\v4.0.30319\MSBuild.exe"
if not defined MSBUILD_EXE if exist "%WINDIR%\Microsoft.NET\Framework\v4.0.30319\MSBuild.exe" set "MSBUILD_EXE=%WINDIR%\Microsoft.NET\Framework\v4.0.30319\MSBuild.exe"

if not defined MSBUILD_EXE goto msbuild_missing

echo [INFO] MSBuild: %MSBUILD_EXE%
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%ROOT%scripts\Generate-GatewayVersionSource.ps1" -Root "%ROOT%."
if errorlevel 1 exit /b %errorlevel%
set "REFERENCE_ARG="
if defined NET48_REFERENCE_ROOT set "REFERENCE_ARG=/p:TargetFrameworkRootPath=%NET48_REFERENCE_ROOT%"
"%MSBUILD_EXE%" "%PROJECT%" /t:Rebuild /m /nologo /verbosity:minimal /p:Configuration=Release /p:Platform=AnyCPU /p:OutputPath=%OUTPUT% %REFERENCE_ARG%
if errorlevel 1 exit /b %errorlevel%
"%MSBUILD_EXE%" "%TEST_PROJECT%" /t:Rebuild /m /nologo /verbosity:minimal /p:Configuration=Release /p:Platform=AnyCPU /p:OutputPath=%TEST_OUTPUT% %REFERENCE_ARG%
if errorlevel 1 exit /b %errorlevel%
if not exist "%OUTPUT%\CollegePortal.Gateway.Host.exe" goto executable_missing
if not exist "%TEST_OUTPUT%\CollegePortal.Gateway.Tests.exe" goto executable_missing
echo [OK] Gateway executable: %OUTPUT%\CollegePortal.Gateway.Host.exe
echo [OK] Gateway tests: %TEST_OUTPUT%\CollegePortal.Gateway.Tests.exe
exit /b 0

:msbuild_missing
echo [ERROR] MSBuild was not found. Install Visual Studio Build Tools and the .NET Framework 4.8 targeting pack.
exit /b 2

:executable_missing
echo [ERROR] MSBuild completed but CollegePortal.Gateway.Host.exe is missing.
exit /b 3
