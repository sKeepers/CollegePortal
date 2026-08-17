using System;
using System.IO;

namespace CollegePortal.Gateway
{
    /// <summary>
    /// Журнал обращений к шлюзу: строка JSON на запрос.
    ///
    /// Файл был записан одной строкой с потерянным экранированием и не
    /// компилировался: в шаблоне строки стояли неэкранированные кавычки, а
    /// <c>Safe</c> звал <c>Replace("\","\\")</c> — незакрытый управляющий
    /// символ. Восстановлено по замыслу; экранирование сделано тем же
    /// способом, что в <see cref="GatewayServer"/>, где оно уцелело.
    /// </summary>
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
                "{{\"ts\":\"{0:O}\",\"started\":\"{1:O}\",\"request_id\":\"{2}\",\"operation\":\"{3}\",\"duration_ms\":{4},\"status\":\"{5}\",\"code\":\"{6}\",\"message\":\"{7}\"}}",
                DateTime.UtcNow,
                started.ToUniversalTime(),
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
            value = value.Replace("\\", "\\\\").Replace("\"", "'").Replace("\r", " ").Replace("\n", " ");
            return value.Length > 500 ? value.Substring(0, 500) : value;
        }
    }
}
