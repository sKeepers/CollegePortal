@echo off
setlocal
set ROOT=%~dp0
if not exist "%ROOT%bin" mkdir "%ROOT%bin"
msbuild "%ROOT%src\FisGatewayAgent.csproj" /p:Configuration=Release /p:OutputPath="%ROOT%bin\"
