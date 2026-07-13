@echo off
setlocal
call "%~dp000-check-prerequisites.cmd" || exit /b 1
call "%~dp001-install.cmd" || exit /b 1
call "%~dp002-configure.cmd" || exit /b 1
call "%~dp003-start.cmd" || exit /b 1
call "%~dp004-health.cmd" || (call "%~dp011-rollback.cmd" & exit /b 1)
echo CollegePortal Gateway installation flow completed. Review installation report manually.
