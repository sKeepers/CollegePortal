@echo off
sc create CollegePortalFisGateway binPath= "C:\CollegePortalFisGateway\CollegePortal.FisGatewayAgent.exe --config C:\CollegePortalFisGateway\config\gateway.private.config" start= demand obj= ".\cp-fis-gateway"
sc description CollegePortalFisGateway "CollegePortal FIS TEST Gateway Agent for ViPNet workstation"
