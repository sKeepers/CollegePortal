@echo off
netsh advfirewall firewall delete rule name="CollegePortal FIS Gateway inbound"
netsh advfirewall firewall delete rule name="CollegePortal FIS TEST outbound"
