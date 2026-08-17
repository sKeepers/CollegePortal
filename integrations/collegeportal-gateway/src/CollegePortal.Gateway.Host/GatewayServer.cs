using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Text;
using System.Threading;

namespace CollegePortal.Gateway
{
    public class GatewayServer : IDisposable
    {
        private readonly GatewayConfig _config;
        private readonly HttpListener _listener = new HttpListener();
        private readonly SecurityValidator _security;
        private readonly AuditLogger _audit;
        private readonly Dictionary<string, IIntegrationAdapter> _adapters;
        private Thread _thread;
        private bool _running;

        public GatewayServer(GatewayConfig config)
        {
            _config = config;
            _listener.Prefixes.Add(config.BindPrefix);
            _audit = new AuditLogger(config.AuditLogPath);
            _security = new SecurityValidator(config, new NonceStore(config.NonceStorePath, config.RequestWindowSeconds), new RateLimiter(config.RateLimitPerMinute));
            _adapters = new Dictionary<string, IIntegrationAdapter>(StringComparer.OrdinalIgnoreCase);
            _adapters["fis"] = new FisIntegrationAdapter(config);
        }

        public void Start()
        {
            _running = true;
            _listener.Start();
            _thread = new Thread(Loop) { IsBackground = true };
            _thread.Start();
        }

        private void Loop()
        {
            while (_running) {
                try { Process(_listener.GetContext()); }
                catch { if (!_running) return; }
            }
        }

        private void Process(HttpListenerContext ctx)
        {
            var started = DateTime.UtcNow;
            var sw = Stopwatch.StartNew();
            var path = ctx.Request.Url.AbsolutePath;
            var body = ReadBody(ctx.Request);
            var requestId = ctx.Request.Headers["X-Gateway-Request-Id"] ?? ctx.Request.Headers["X-FIS-Request-Id"] ?? Guid.NewGuid().ToString("N");
            var requireSignature = !(ctx.Request.HttpMethod == "GET" && (path == "/health" || path == "/version" || path == "/capabilities" || path == "/adapters"));
            var validation = _security.Validate(ctx.Request.HttpMethod, path, ctx.Request.RemoteEndPoint.Address.ToString(), Headers(ctx.Request), body, requireSignature);
            if (!validation.Success) {
                Write(ctx, 403, Error(validation.Code, validation.Message));
                _audit.Write(requestId, path, started, (int)sw.ElapsedMilliseconds, "denied", validation.Code, validation.Message);
                return;
            }
            GatewayPayload payload = null;
            var status = 200;
            if (path == "/health" && ctx.Request.HttpMethod == "GET") payload = GatewayPayload.Success("healthy", 0, "CollegePortal Gateway is running.");
            else if (path == "/version" && ctx.Request.HttpMethod == "GET") { Write(ctx, 200, VersionJson()); return; }
            else if (path == "/capabilities" && ctx.Request.HttpMethod == "GET") { Write(ctx, 200, CapabilitiesJson()); return; }
            else if (path == "/adapters" && ctx.Request.HttpMethod == "GET") { Write(ctx, 200, AdaptersJson()); return; }
            else if (path == "/adapters/fis/health" && ctx.Request.HttpMethod == "GET") payload = _adapters["fis"].HealthCheck();
            else if (path == "/diagnostics/run" && ctx.Request.HttpMethod == "POST") payload = RunDiagnostics();
            else if (path == "/diagnostics/latest" && ctx.Request.HttpMethod == "GET") { Write(ctx, 200, LatestDiagnosticsJson()); return; }
            else if (path == "/adapters/fis/zkspd/check" && ctx.Request.HttpMethod == "POST") payload = _adapters["fis"].ExecuteReadOnly("zkspd_check", Encoding.UTF8.GetString(body));
            else if (path == "/adapters/fis/test/dictionaries/list" && ctx.Request.HttpMethod == "POST") payload = _adapters["fis"].ExecuteReadOnly("dictionaries_list", Encoding.UTF8.GetString(body));
            else if (path == "/adapters/fis/test/dictionaries/details" && ctx.Request.HttpMethod == "POST") payload = _adapters["fis"].ExecuteReadOnly("dictionaries_details", Encoding.UTF8.GetString(body));
            else if (path == "/adapters/fis/test/institution/info" && ctx.Request.HttpMethod == "POST") payload = _adapters["fis"].ExecuteReadOnly("institution_info", Encoding.UTF8.GetString(body));
            else if (path == "/adapters/fis/test/check-application" && ctx.Request.HttpMethod == "POST") payload = _adapters["fis"].ExecuteReadOnly("check_application", Encoding.UTF8.GetString(body));
            else if (path == "/adapters/fis/test/validate" || path == "/adapters/fis/test/import" || path == "/adapters/fis/test/import-result") payload = _adapters["fis"].ExecuteCommand(LastSegment(path), Encoding.UTF8.GetString(body));
            else if (path == "/zkspd/check" && ctx.Request.HttpMethod == "POST") { AddDeprecated(ctx); payload = _adapters["fis"].ExecuteReadOnly("zkspd_check", Encoding.UTF8.GetString(body)); }
            else if (path == "/fis/test/dictionaries/list" && ctx.Request.HttpMethod == "POST") { AddDeprecated(ctx); payload = _adapters["fis"].ExecuteReadOnly("dictionaries_list", Encoding.UTF8.GetString(body)); }
            else if (path == "/fis/test/dictionaries/details" && ctx.Request.HttpMethod == "POST") { AddDeprecated(ctx); payload = _adapters["fis"].ExecuteReadOnly("dictionaries_details", Encoding.UTF8.GetString(body)); }
            else if (path == "/fis/test/institution/info" && ctx.Request.HttpMethod == "POST") { AddDeprecated(ctx); payload = _adapters["fis"].ExecuteReadOnly("institution_info", Encoding.UTF8.GetString(body)); }
            else if (path == "/fis/test/check-application" && ctx.Request.HttpMethod == "POST") { AddDeprecated(ctx); payload = _adapters["fis"].ExecuteReadOnly("check_application", Encoding.UTF8.GetString(body)); }
            else if (path == "/fis/test/validate" || path == "/fis/test/import" || path == "/fis/test/import-result") { AddDeprecated(ctx); payload = _adapters["fis"].ExecuteCommand(LastSegment(path), Encoding.UTF8.GetString(body)); }
            else if (path.StartsWith("/fis/production/") || path.StartsWith("/adapters/fis/production/")) { Write(ctx, 403, Error("production_disabled", "FIS production endpoint is hard-disabled.")); return; }
            else { Write(ctx, 404, Error("not_found", "Endpoint not found.")); return; }
            if (!payload.Ok) status = payload.Code == "operation_disabled" || payload.Code == "production_disabled" ? 403 : 502;
            Write(ctx, status, Payload(payload, _config.ServiceVersion));
            _audit.Write(requestId, path, started, (int)sw.ElapsedMilliseconds, payload.Ok ? "ok" : "failed", payload.Code, payload.Message);
        }
        private GatewayPayload RunDiagnostics()
        {
            var json = "{\"gateway_version\":\"" + Json(_config.ServiceVersion) + "\",\"fis_health\":" + Payload(_adapters["fis"].HealthCheck(), _config.ServiceVersion) + ",\"production_enabled\":false}";
            Directory.CreateDirectory(Path.GetDirectoryName(_config.DiagnosticsPath));
            File.WriteAllText(_config.DiagnosticsPath, json, Encoding.UTF8);
            return GatewayPayload.Success("diagnostics_completed", 0, "Diagnostics collected with secrets redacted.");
        }
        private string LatestDiagnosticsJson()
        {
            if (!File.Exists(_config.DiagnosticsPath)) return "{\"ok\":false,\"error_code\":\"diagnostics_missing\",\"message\":\"No diagnostics were collected yet.\"}";
            return File.ReadAllText(_config.DiagnosticsPath, Encoding.UTF8);
        }
        private string VersionJson() { return "{\"ok\":true,\"name\":\"CollegePortal Gateway\",\"version\":\"" + Json(_config.ServiceVersion) + "\",\"service_name\":\"CollegePortalGateway\",\"install_root\":\"C:\\\\CollegePortalGateway\",\"production_enabled\":false}"; }
        private string AdaptersJson() { return "{\"ok\":true,\"adapters\":[{\"name\":\"fis\",\"version\":\"" + Json(_adapters["fis"].Version) + "\",\"enabled\":true}]}"; }
        private string CapabilitiesJson() { return "{\"ok\":true,\"gateway\":[\"health\",\"version\",\"capabilities\",\"adapters\",\"diagnostics\"],\"adapters\":{\"fis\":" + _adapters["fis"].GetCapabilitiesJson() + "}}"; }
        private static byte[] ReadBody(HttpListenerRequest request) { using (var ms = new MemoryStream()) { request.InputStream.CopyTo(ms); return ms.ToArray(); } }
        private static Dictionary<string, string> Headers(HttpListenerRequest request) { var dict = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase); foreach (string key in request.Headers.Keys) dict[key] = request.Headers[key]; return dict; }
        private static void Write(HttpListenerContext ctx, int status, string json) { var bytes = Encoding.UTF8.GetBytes(json); ctx.Response.StatusCode = status; ctx.Response.ContentType = "application/json; charset=utf-8"; ctx.Response.ContentLength64 = bytes.Length; ctx.Response.OutputStream.Write(bytes, 0, bytes.Length); ctx.Response.OutputStream.Close(); }
        // `data` появляется только когда ФИС что-то вернула. Экранируется он иначе,
        // чем сообщения: `Json()` заменяет кавычки на апострофы, и для текста это
        // годится, а для XML означало бы порчу данных.
        private static string Payload(GatewayPayload payload, string version) { return "{\"ok\":" + (payload.Ok ? "true" : "false") + ",\"error_code\":\"" + Json(payload.Code) + "\",\"message\":\"" + Json(payload.Message) + "\",\"latency_ms\":" + payload.LatencyMs + ",\"gateway_version\":\"" + Json(version) + "\"" + (string.IsNullOrEmpty(payload.Data) ? "" : ",\"data\":\"" + JsonString(payload.Data) + "\"") + "}"; }

        /// <summary>Строгое экранирование строки JSON — без потери символов.</summary>
        private static string JsonString(string value)
        {
            var builder = new StringBuilder((value ?? "").Length + 32);

            foreach (var character in value ?? "") {
                switch (character) {
                    case '"': builder.Append("\\\""); break;
                    case '\\': builder.Append("\\\\"); break;
                    case '\b': builder.Append("\\b"); break;
                    case '\f': builder.Append("\\f"); break;
                    case '\n': builder.Append("\\n"); break;
                    case '\r': builder.Append("\\r"); break;
                    case '\t': builder.Append("\\t"); break;
                    default:
                        if (character < ' ') { builder.Append("\\u").Append(((int)character).ToString("x4")); }
                        else { builder.Append(character); }
                        break;
                }
            }

            return builder.ToString();
        }
        private static string Error(string code, string message) { return "{\"ok\":false,\"error_code\":\"" + Json(code) + "\",\"message\":\"" + Json(message) + "\"}"; }
        private static string Json(string value) { return (value ?? "").Replace("\\", "\\\\").Replace("\"", "'").Replace("\r", " ").Replace("\n", " "); }
        private static string LastSegment(string path) { var index = path.LastIndexOf('/'); return index >= 0 ? path.Substring(index + 1) : path; }
        private static void AddDeprecated(HttpListenerContext ctx) { ctx.Response.Headers["X-CollegePortal-Deprecated"] = "Use /adapters/fis/... endpoints."; }
        public void Dispose() { _running = false; if (_listener.IsListening) _listener.Stop(); _listener.Close(); }
    }
}
