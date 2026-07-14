using System;
using System.ServiceProcess;

namespace CollegePortal.Gateway
{
    internal static class Program
    {
        public static void Main(string[] args)
        {
            var configPath = @"C:\CollegePortalGateway\config\gateway.private.config";
            for (var index = 0; index < args.Length - 1; index++) {
                if (string.Equals(args[index], "--config", StringComparison.OrdinalIgnoreCase)) {
                    configPath = args[index + 1];
                    break;
                }
            }
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
