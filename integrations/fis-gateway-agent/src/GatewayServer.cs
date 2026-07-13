using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Text;
using System.Threading;

namespace CollegePortal.FisGatewayAgent
{
    public class GatewayServer : IDisposable
    {
        private readonly GatewayConfig _config;
        private readonly HttpListener _listener = new HttpListener();
        private readonly SecurityValidator _security;
        private readonly AuditLogger _audit;
        private readonly FisSoapClient _soap;
        private Thread _thread;
        private bool _running;

        public GatewayServer(GatewayConfig config)
        {
            _config = config;
            _listener.Prefixes.Add(config.BindPrefix);
            _audit = new AuditLogger(config.AuditLogPath);
            _security = new SecurityValidator(config, new NonceStore(config.NonceStorePath, config.RequestWindowSeconds), new RateLimiter(config.RateLimitPerMinute));
            _soap = new FisSoapClient(config);
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
            while (_running)
            {
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
            var requestId = ctx.Request.Headers["X-FIS-Request-Id"] ?? Guid.NewGuid().ToString("N");
            var requireSignature = !(ctx.Request.HttpMethod == "GET" && (path == "/health" || path == "/version"));
            var validation = _security.Validate(ctx.Request.HttpMethod, path, ctx.Request.RemoteEndPoint.Address.ToString(), Headers(ctx.Request), body, requireSignature);

            if (!validation.Success)
            {
                Write(ctx, 403, Error(validation.Code, validation.Message));
                _audit.Write(requestId, path, started, (int)sw.ElapsedMilliseconds, "denied", validation.Code, validation.Message);
                return;
            }

            GatewayPayload payload;
            if (path == "/health" && ctx.Request.HttpMethod == "GET") payload = GatewayPayload.Ok("healthy", 0, "Gateway Agent is running.");
            else if (path == "/version" && ctx.Request.HttpMethod == "GET") payload = GatewayPayload.Ok("version", 0, _config.ServiceVersion);
            else if (path == "/zkspd/check" && ctx.Request.HttpMethod == "POST") payload = _soap.ZkspdCheck();
            else if (path == "/fis/test/dictionaries/list" && ctx.Request.HttpMethod == "POST") payload = _soap.CallReadOnly("GetTestDictionariesList", "");
            else if (path == "/fis/test/dictionaries/details" && ctx.Request.HttpMethod == "POST") payload = _soap.CallReadOnly("GetTestDictionaryDetails", "");
            else if (path == "/fis/test/institution/info" && ctx.Request.HttpMethod == "POST") payload = _soap.CallReadOnly("GetInstitutionInfo", "");
            else if (path == "/fis/test/check-application" && ctx.Request.HttpMethod == "POST") payload = _soap.CallReadOnly("GetTestCheckApplication", "");
            else if (path == "/fis/test/validate" || path == "/fis/test/import" || path == "/fis/test/import-result") payload = GatewayPayload.Fail("operation_disabled", 0, "official_application_xsd_missing");
            else if (path.StartsWith("/fis/production/"))
            {
                Write(ctx, 403, Error("production_denied", "Production FIS endpoint is disabled."));
                return;
            }
            else
            {
                Write(ctx, 404, Error("not_found", "Endpoint not found."));
                return;
            }

            var status = payload.Ok ? 200 : (payload.Code == "operation_disabled" ? 409 : 502);
            Write(ctx, status, Payload(payload, _config.ServiceVersion));
            _audit.Write(requestId, path, started, (int)sw.ElapsedMilliseconds, payload.Ok ? "ok" : "failed", payload.Code, payload.Message);
        }

        private static byte[] ReadBody(HttpListenerRequest request)
        {
            using (var ms = new MemoryStream())
            {
                request.InputStream.CopyTo(ms);
                return ms.ToArray();
            }
        }

        private static Dictionary<string, string> Headers(HttpListenerRequest request)
        {
            var dict = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            foreach (string key in request.Headers.Keys) dict[key] = request.Headers[key];
            return dict;
        }

        private static void Write(HttpListenerContext ctx, int status, string json)
        {
            var bytes = Encoding.UTF8.GetBytes(json);
            ctx.Response.StatusCode = status;
            ctx.Response.ContentType = "application/json; charset=utf-8";
            ctx.Response.ContentLength64 = bytes.Length;
            ctx.Response.OutputStream.Write(bytes, 0, bytes.Length);
            ctx.Response.OutputStream.Close();
        }

        private static string Payload(GatewayPayload payload, string version)
        {
            return "{\"ok\":" + (payload.Ok ? "true" : "false")
                + ",\"error_code\":\"" + Json(payload.Code) + "\""
                + ",\"message\":\"" + Json(payload.Message) + "\""
                + ",\"latency_ms\":" + payload.LatencyMs
                + ",\"gateway_version\":\"" + Json(version) + "\"}";
        }

        private static string Error(string code, string message)
        {
            return "{\"ok\":false,\"error_code\":\"" + Json(code) + "\",\"message\":\"" + Json(message) + "\"}";
        }

        private static string Json(string value)
        {
            return (value ?? "").Replace("\\", "\\\\").Replace("\"", "'").Replace("\r", " ").Replace("\n", " ");
        }

        public void Dispose()
        {
            _running = false;
            if (_listener.IsListening) _listener.Stop();
            _listener.Close();
        }
    }
}
