@echo off
set PORT=8099
set PORTAL_IP=192.168.34.104
netsh advfirewall firewall add rule name="CollegePortal FIS Gateway inbound" dir=in action=allow protocol=TCP localport=%PORT% remoteip=%PORTAL_IP%
netsh advfirewall firewall add rule name="CollegePortal FIS TEST outbound" dir=out action=allow protocol=TCP remoteip=10.0.3.1 remoteport=8383
