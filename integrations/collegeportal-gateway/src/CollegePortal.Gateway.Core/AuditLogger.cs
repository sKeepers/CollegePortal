using System;
using System.IO;
using System.Text.RegularExpressions;

namespace CollegePortal.Gateway
{
    public class AuditLogger
    {
        private readonly string _path;
        private readonly object _lock = new object();
        private const long MaxBytes = 10 * 1024 * 1024;

        public AuditLogger(string path)
        {
            _path = path;
            Directory.CreateDirectory(Path.GetDirectoryName(path));
        }

        public void Write(string requestId, string operation, DateTime started, int durationMs, string status, string code, string message)
        {
            var line = string.Format(
                "{{\"ts\":\"{0:O}\",\"request_id\":\"{1}\",\"operation\":\"{2}\",\"duration_ms\":{3},\"status\":\"{4}\",\"code\":\"{5}\",\"message\":\"{6}\"}}",
                DateTime.UtcNow,
                Safe(requestId),
                Safe(operation),
                durationMs,
                Safe(status),
                Safe(code),
                Safe(message));

            lock (_lock)
            {
                RotateIfNeeded();
                File.AppendAllText(_path, line + Environment.NewLine);
            }
        }

        private void RotateIfNeeded()
        {
            if (!File.Exists(_path) || new FileInfo(_path).Length < MaxBytes) return;
            var previous = _path + ".1";
            if (File.Exists(previous)) File.Delete(previous);
            File.Move(_path, previous);
        }

        private static string Safe(string value)
        {
            if (value == null)
            {
                return "";
            }

            value = Regex.Replace(value, "(?i)(password|pass|secret|token|authorization)\\s*[:=]\\s*[^,; ]+", "$1=[redacted]");
            value = value
                .Replace("\\", "\\\\")
                .Replace("\"", "'")
                .Replace("\r", " ")
                .Replace("\n", " ");

            return value.Length > 500 ? value.Substring(0, 500) : value;
        }
    }
}
