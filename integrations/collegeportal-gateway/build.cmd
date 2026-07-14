@echo off
set ROOT=%~dp0
msbuild "%ROOT%src\CollegePortal.Gateway.Host\CollegePortal.Gateway.Host.csproj" /p:Configuration=Release /p:OutputPath="%ROOT%bin"
