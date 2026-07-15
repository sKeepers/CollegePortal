using System;
using System.IO;
using System.ServiceProcess;
using System.Threading;

namespace CollegePortal.Gateway
{
    internal static class Program
    {
        public static void Main(string[] args)
        {
            var configPath = ConfigPath(args);
            if (!File.Exists(configPath)) {
                Console.Error.WriteLine("Gateway config file was not found: " + configPath);
                Environment.ExitCode = 2;
                return;
            }

            GatewayConfig config;
            try {
                config = GatewayConfig.Load(configPath);
                ValidateConfig(config);
            }
            catch (Exception exception) {
                Console.Error.WriteLine("Gateway config is invalid: " + exception.Message);
                Environment.ExitCode = 2;
                return;
            }

            if (HasArgument(args, "--check-config")) {
                Console.WriteLine("Gateway config is valid.");
                return;
            }

            if (Environment.UserInteractive || HasArgument(args, "--console")) {
                using (var server = new GatewayServer(config)) {
                    server.Start();
                    Console.WriteLine("CollegePortal Gateway started at " + config.BindPrefix);
                    using (var stopped = new ManualResetEvent(false)) {
                        var runForSeconds = IntegerArgument(args, "--run-for-seconds");
                        Timer timer = null;
                        if (runForSeconds > 0) {
                            timer = new Timer(delegate(object state) { stopped.Set(); }, null,
                                TimeSpan.FromSeconds(runForSeconds), TimeSpan.FromMilliseconds(-1));
                        }
                        Console.CancelKeyPress += delegate(object sender, ConsoleCancelEventArgs eventArgs) {
                            eventArgs.Cancel = true;
                            stopped.Set();
                        };
                        stopped.WaitOne();
                        if (timer != null) timer.Dispose();
                    }
                }
                return;
            }
            ServiceBase.Run(new GatewayWindowsService(config));
        }

        private static string ConfigPath(string[] args)
        {
            for (var index = 0; index < args.Length - 1; index++) {
                if (string.Equals(args[index], "--config", StringComparison.OrdinalIgnoreCase)) return args[index + 1];
            }
            return @"C:\CollegePortalGateway\config\gateway.private.config";
        }

        private static bool HasArgument(string[] args, string expected)
        {
            foreach (var argument in args) {
                if (string.Equals(argument, expected, StringComparison.OrdinalIgnoreCase)) return true;
            }
            return false;
        }

        private static int IntegerArgument(string[] args, string expected)
        {
            for (var index = 0; index < args.Length - 1; index++) {
                int value;
                if (string.Equals(args[index], expected, StringComparison.OrdinalIgnoreCase)
                    && int.TryParse(args[index + 1], out value)) return value;
            }
            return 0;
        }

        private static void ValidateConfig(GatewayConfig config)
        {
            if (string.IsNullOrWhiteSpace(config.SharedSecret)
                || config.SharedSecret.IndexOf("CHANGE_ME", StringComparison.OrdinalIgnoreCase) >= 0
                || config.SharedSecret.Length < 32) {
                throw new InvalidOperationException("SharedSecret must contain at least 32 characters and must not be a placeholder.");
            }
            if (config.AllowedPortalIps == null || config.AllowedPortalIps.Length == 0) {
                throw new InvalidOperationException("AllowedPortalIps must not be empty.");
            }
            if (config.EnableDangerousOperations) {
                throw new InvalidOperationException("EnableDangerousOperations must remain false until a separately approved task.");
            }
            if (config.FisProductionEnabled) {
                throw new InvalidOperationException("FisProductionEnabled must remain false.");
            }
        }
    }
}
