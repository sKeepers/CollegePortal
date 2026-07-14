@echo off
setlocal
net session >nul 2>&1 || (echo ERROR: run as Administrator & exit /b 1)
ver
reg query "HKLM\SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full" /v Release >nul 2>&1 || (echo ERROR: .NET Framework 4.8 is required & exit /b 1)
echo OK: prerequisites look compatible with Windows service installation.
