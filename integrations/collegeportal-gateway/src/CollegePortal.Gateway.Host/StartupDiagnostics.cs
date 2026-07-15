using System;
using System.Collections.Generic;
using System.Configuration;
using System.IO;
using System.Net;
using System.Reflection;
using System.Text;
using System.Threading.Tasks;
using Microsoft.Win32;

namespace CollegePortal.Gateway
{
    internal sealed class GatewayStartupException : Exception
    {
        public string Code { get; private set; }

        public GatewayStartupException(string code, string message) : base(message) { Code = code; }
        public GatewayStartupException(string code, string message, Exception inner) : base(message, inner) { Code = code; }
    }

    internal static class StartupDiagnostics
    {
        private static readonly object Sync = new object();
        private static readonly List<string> SensitiveValues = new List<string>();
        private static readonly Encoding Utf8 = new UTF8Encoding(false);
        private static string _logPath;

        public static string LogPath { get { return _logPath; } }

        public static bool Initialize(string configPath, string mode)
        {
            try {
                var installRoot = InstallRootFromConfigPath(configPath);
                var logDirectory = Path.Combine(installRoot, "logs");
                Directory.CreateDirectory(logDirectory);
                _logPath = Path.Combine(logDirectory, "startup.log");
                File.AppendAllText(_logPath, Environment.NewLine, Utf8);
                Write("startup", "begin");
                Write("timestamp_utc", DateTime.UtcNow.ToString("yyyy-MM-ddTHH:mm:ss.fffZ"));
                Write("os_version", Environment.OSVersion.VersionString);
                Write("dotnet_release", DotNetFrameworkRelease().ToString());
                Write("clr_version", Environment.Version.ToString());
                Write("process_architecture", IntPtr.Size == 8 ? "x64" : "x86");
                Write("current_directory", Environment.CurrentDirectory);
                Write("executable_path", ExecutablePath());
                Write("config_path", Path.GetFullPath(configPath));
                Write("config_file_exists", File.Exists(configPath).ToString());
                Write("log_directory", logDirectory);
                Write("log_directory_writable", "True");
                Write("mode", mode);
                return true;
            }
            catch (Exception exception) {
                Console.Error.WriteLine("[LOG_PATH_DENIED] Не удалось создать startup.log: " + exception.Message);
                return false;
            }
        }

        public static void RegisterGlobalHandlers()
        {
            AppDomain.CurrentDomain.UnhandledException += delegate(object sender, UnhandledExceptionEventArgs args) {
                var exception = args.ExceptionObject as Exception
                    ?? new Exception("Unhandled non-Exception object: " + Convert.ToString(args.ExceptionObject));
                WriteException("UNHANDLED_EXCEPTION", exception);
                Write("runtime_terminating", args.IsTerminating.ToString());
            };
            TaskScheduler.UnobservedTaskException += delegate(object sender, UnobservedTaskExceptionEventArgs args) {
                WriteException("UNOBSERVED_TASK_EXCEPTION", args.Exception);
                args.SetObserved();
            };
        }

        public static void RegisterSensitiveValue(string value)
        {
            if (string.IsNullOrEmpty(value)) return;
            lock (Sync) {
                if (!SensitiveValues.Contains(value)) SensitiveValues.Add(value);
            }
        }

        public static void ValidatePreflight(string configPath, GatewayConfig config)
        {
            if (!File.Exists(ExecutablePath())) {
                throw new GatewayStartupException("BINARY_MISSING", "CollegePortal.Gateway.Host.exe не найден.");
            }
            if (!File.Exists(configPath)) {
                throw new GatewayStartupException("CONFIG_NOT_FOUND", "gateway.private.config не найден: " + configPath);
            }
            if (DotNetFrameworkRelease() < 528040) {
                throw new GatewayStartupException("UNSUPPORTED_RUNTIME", "Требуется .NET Framework 4.8 (Release >= 528040).");
            }

            var versionPath = Path.Combine(config.InstallRoot, "VERSION");
            if (!File.Exists(versionPath)) {
                throw new GatewayStartupException("BINARY_MISSING", "Обязательный файл VERSION не найден: " + versionPath);
            }

            Uri listenUri;
            var normalizedPrefix = (config.BindPrefix ?? "").Replace("+", "127.0.0.1").Replace("*", "127.0.0.1");
            if (!Uri.TryCreate(normalizedPrefix, UriKind.Absolute, out listenUri)
                || !string.Equals(listenUri.Scheme, Uri.UriSchemeHttp, StringComparison.OrdinalIgnoreCase)
                || listenUri.Port <= 0) {
                throw new GatewayStartupException("CONFIG_INVALID", "BindPrefix должен содержать корректный HTTP URL и порт.");
            }

            try {
                foreach (var reference in Assembly.GetExecutingAssembly().GetReferencedAssemblies()) Assembly.Load(reference);
            }
            catch (BadImageFormatException exception) {
                throw new GatewayStartupException("ASSEMBLY_LOAD_FAILED", "Обнаружена DLL неверной архитектуры.", exception);
            }
            catch (Exception exception) {
                throw new GatewayStartupException("ASSEMBLY_LOAD_FAILED", "Не удалось загрузить обязательную сборку.", exception);
            }

            Write("preflight", "passed");
            Write("version_path", versionPath);
            Write("listen_url", config.BindPrefix);
            Write("listen_port", listenUri.Port.ToString());
        }

        public static string Classify(Exception exception)
        {
            var startup = exception as GatewayStartupException;
            if (startup != null) return startup.Code;
            if (exception is ConfigurationErrorsException || exception is FormatException || exception is ArgumentException) return "CONFIG_INVALID";
            if (exception is BadImageFormatException || exception is FileLoadException || exception is FileNotFoundException) return "ASSEMBLY_LOAD_FAILED";
            if (exception is PlatformNotSupportedException) return "UNSUPPORTED_RUNTIME";
            if (exception is UnauthorizedAccessException) return "LOG_PATH_DENIED";

            var listener = exception as HttpListenerException;
            if (listener != null) {
                if (listener.ErrorCode == 5) return "URLACL_MISSING";
                if (listener.ErrorCode == 32 || listener.ErrorCode == 183 || listener.ErrorCode == 10048) return "PORT_IN_USE";
                return "HTTP_LISTENER_FAILED";
            }
            return "STARTUP_FAILED";
        }

        public static int ExitCode(string code)
        {
            if (code == "CONFIG_NOT_FOUND" || code == "CONFIG_INVALID") return 2;
            if (code == "LOG_PATH_DENIED") return 3;
            if (code == "BINARY_MISSING" || code == "ASSEMBLY_LOAD_FAILED") return 4;
            if (code == "UNSUPPORTED_RUNTIME") return 5;
            if (code == "PORT_IN_USE" || code == "URLACL_MISSING" || code == "HTTP_LISTENER_FAILED") return 6;
            return 10;
        }

        public static void WriteException(string code, Exception exception)
        {
            Write("error_code", code);
            Write("exception_type", exception.GetType().FullName);
            Write("exception_hresult", "0x" + exception.HResult.ToString("X8"));
            WriteMultiline("exception_to_string", Redact(exception.ToString()));
            var inner = exception.InnerException;
            var depth = 0;
            while (inner != null) {
                Write("inner_" + depth + "_type", inner.GetType().FullName);
                Write("inner_" + depth + "_hresult", "0x" + inner.HResult.ToString("X8"));
                WriteMultiline("inner_" + depth + "_exception", Redact(inner.ToString()));
                inner = inner.InnerException;
                depth++;
            }
            Write("startup", "failed");
        }

        public static string Redact(string value)
        {
            var result = value ?? "";
            lock (Sync) {
                foreach (var sensitive in SensitiveValues) {
                    if (!string.IsNullOrEmpty(sensitive)) result = result.Replace(sensitive, "[REDACTED]");
                }
            }
            return result;
        }

        public static void Write(string key, string value)
        {
            Append(DateTime.UtcNow.ToString("yyyy-MM-ddTHH:mm:ss.fffZ") + " " + key + "=" + SingleLine(Redact(value)));
        }

        private static void WriteMultiline(string key, string value)
        {
            var normalized = (value ?? "").Replace("\r\n", "\n").Replace('\r', '\n');
            var lines = normalized.Split('\n');
            for (var index = 0; index < lines.Length; index++) Write(key + "[" + index + "]", lines[index]);
        }

        private static void Append(string line)
        {
            if (string.IsNullOrEmpty(_logPath)) return;
            lock (Sync) File.AppendAllText(_logPath, line + Environment.NewLine, Utf8);
        }

        private static string SingleLine(string value)
        {
            return (value ?? "").Replace("\r", " ").Replace("\n", " ");
        }

        private static string InstallRootFromConfigPath(string configPath)
        {
            var directory = Directory.GetParent(Path.GetFullPath(configPath));
            if (directory == null) return @"C:\CollegePortalGateway";
            if (string.Equals(directory.Name, "config", StringComparison.OrdinalIgnoreCase) && directory.Parent != null) return directory.Parent.FullName;
            return directory.FullName;
        }

        private static string ExecutablePath()
        {
            return Assembly.GetExecutingAssembly().Location;
        }

        private static int DotNetFrameworkRelease()
        {
            try {
                using (var key = Registry.LocalMachine.OpenSubKey(@"SOFTWARE\Microsoft\NET Framework Setup\NDP\v4\Full")) {
                    if (key == null) return 0;
                    var value = key.GetValue("Release");
                    return value == null ? 0 : Convert.ToInt32(value);
                }
            }
            catch { return 0; }
        }
    }
}
