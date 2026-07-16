using System;
using System.IO;
using System.ServiceProcess;
using System.Threading;

namespace CollegePortal.Gateway
{
    internal static class Program
    {
        public static int Main(string[] args)
        {
            var configPath = ConfigPath(args);
            var mode = Environment.UserInteractive || HasArgument(args, "--console") ? "console" : "service";
            StartupDiagnostics.RegisterGlobalHandlers();
            if (!StartupDiagnostics.Initialize(configPath, mode)) return StartupDiagnostics.ExitCode("LOG_PATH_DENIED");

            try {
                return Run(args, configPath, mode);
            }
            catch (Exception exception) {
                var code = StartupDiagnostics.Classify(exception);
                StartupDiagnostics.WriteException(code, exception);
                Console.Error.WriteLine("[" + code + "] Запуск CollegePortal Gateway остановлен. Подробности: " + StartupDiagnostics.LogPath);
                return StartupDiagnostics.ExitCode(code);
            }
        }

        private static int Run(string[] args, string configPath, string mode)
        {
            if (!File.Exists(configPath)) throw new GatewayStartupException("CONFIG_NOT_FOUND", "gateway.private.config не найден: " + configPath);

            var config = GatewayConfig.Load(configPath);
            StartupDiagnostics.RegisterSensitiveValue(config.SharedSecret);
            ValidateConfig(config);
            StartupDiagnostics.ValidatePreflight(configPath, config);

            if (HasArgument(args, "--check-config")) {
                Console.WriteLine("Gateway config is valid.");
                StartupDiagnostics.Write("startup", "config_checked");
                return 0;
            }

            if (mode == "console") {
                using (var server = new GatewayServer(config)) {
                    server.Start();
                    StartupDiagnostics.Write("startup", "running");
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
                StartupDiagnostics.Write("startup", "stopped");
                return 0;
            }
            ServiceBase.Run(new GatewayWindowsService(config));
            return 0;
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
