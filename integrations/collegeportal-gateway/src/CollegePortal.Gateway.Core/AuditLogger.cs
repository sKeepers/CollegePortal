using System;
using System.IO;

namespace CollegePortal.Gateway
{
    public class AuditLogger
    {
        private readonly string _path;
        private readonly object _lock = new object();

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

            lock (_lock) {
                File.AppendAllText(_path, line + Environment.NewLine);
            }
        }

        private static string Safe(string value)
        {
            if (value == null) return "";

            value = value
                .Replace("\\", "\\\\")
                .Replace("\"", "'")
                .Replace("\r", " ")
                .Replace("\n", " ");

            return value.Length > 500 ? value.Substring(0, 500) : value;
        }
    }
}
