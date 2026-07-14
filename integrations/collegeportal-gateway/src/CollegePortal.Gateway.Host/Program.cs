using System;
using System.ServiceProcess;

namespace CollegePortal.Gateway
{
    internal static class Program
    {
        public static void Main(string[] args)
        {
            var configPath = args.Length > 1 && args[0] == "--config" ? args[1] : @"C:\CollegePortalGateway\config\gateway.private.config";
            var config = GatewayConfig.Load(configPath);
            if (Environment.UserInteractive || (args.Length > 0 && args[0] == "--console")) {
                using (var server = new GatewayServer(config)) {
                    server.Start();
                    Console.WriteLine("CollegePortal Gateway started at " + config.BindPrefix);
                    Console.ReadLine();
                }
                return;
            }
            ServiceBase.Run(new GatewayWindowsService(config));
        }
    }
}
