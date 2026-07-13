@echo off
powershell -NoProfile -Command "Invoke-WebRequest -UseBasicParsing http://127.0.0.1:8099/health | Select-Object -ExpandProperty Content"
