@echo off
powershell -NoProfile -ExecutionPolicy Bypass -Command "Invoke-WebRequest -UseBasicParsing http://127.0.0.1:8099/health; Invoke-WebRequest -UseBasicParsing http://127.0.0.1:8099/version"
