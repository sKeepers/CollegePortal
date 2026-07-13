using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;

namespace CollegePortal.Gateway
{
    public class GatewayConfig
    {
        public string BindPrefix = "http://+:8099/";
        public string[] AllowedPortalIps = new string[0];
        public string SharedSecret = "";
        public string FisTestEndpoint = "http://10.0.3.1:8383/api/import/ImportService.svc";
        public bool EnableDangerousOperations = false;
        public bool FisProductionEnabled = false;
        public int MaxBodyBytes = 1048576;
        public int RequestWindowSeconds = 300;
        public int RateLimitPerMinute = 60;
        public int ConnectTimeoutSeconds = 5;
        public int RequestTimeoutSeconds = 30;
        public string InstallRoot = @"C:\CollegePortalGateway";
        public string AuditLogPath = @"C:\CollegePortalGateway\logs\audit.log";
        public string NonceStorePath = @"C:\CollegePortalGateway\cache\nonces.txt";
        public string DiagnosticsPath = @"C:\CollegePortalGateway\diagnostics\latest.json";
        public string ServiceVersion = "0.2.0-dev";
        public string OfficialSpecStatus = "not_imported";

        public static GatewayConfig Load(string path)
        {
            var cfg = new GatewayConfig();
            if (!File.Exists(path)) return cfg;
            var values = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
            foreach (var raw in File.ReadAllLines(path)) {
                var line = raw.Trim();
                if (line.Length == 0 || line.StartsWith("#") || !line.Contains("=")) continue;
                var parts = line.Split(new[] { '=' }, 2);
                values[parts[0].Trim()] = parts[1].Trim();
            }
            cfg.BindPrefix = Get(values, "BindPrefix", cfg.BindPrefix);
            cfg.AllowedPortalIps = Get(values, "AllowedPortalIps", "").Split(',').Select(x => x.Trim()).Where(x => x.Length > 0).ToArray();
            cfg.SharedSecret = Get(values, "SharedSecret", cfg.SharedSecret);
            cfg.FisTestEndpoint = Get(values, "FisTestEndpoint", cfg.FisTestEndpoint);
            cfg.EnableDangerousOperations = bool.Parse(Get(values, "EnableDangerousOperations", cfg.EnableDangerousOperations.ToString()));
            cfg.FisProductionEnabled = bool.Parse(Get(values, "FisProductionEnabled", cfg.FisProductionEnabled.ToString()));
            cfg.MaxBodyBytes = int.Parse(Get(values, "MaxBodyBytes", cfg.MaxBodyBytes.ToString()));
            cfg.RequestWindowSeconds = int.Parse(Get(values, "RequestWindowSeconds", cfg.RequestWindowSeconds.ToString()));
            cfg.RateLimitPerMinute = int.Parse(Get(values, "RateLimitPerMinute", cfg.RateLimitPerMinute.ToString()));
            cfg.ConnectTimeoutSeconds = int.Parse(Get(values, "ConnectTimeoutSeconds", cfg.ConnectTimeoutSeconds.ToString()));
            cfg.RequestTimeoutSeconds = int.Parse(Get(values, "RequestTimeoutSeconds", cfg.RequestTimeoutSeconds.ToString()));
            cfg.InstallRoot = Get(values, "InstallRoot", cfg.InstallRoot);
            cfg.AuditLogPath = Get(values, "AuditLogPath", cfg.AuditLogPath);
            cfg.NonceStorePath = Get(values, "NonceStorePath", cfg.NonceStorePath);
            cfg.DiagnosticsPath = Get(values, "DiagnosticsPath", cfg.DiagnosticsPath);
            cfg.ServiceVersion = Get(values, "ServiceVersion", cfg.ServiceVersion);
            cfg.OfficialSpecStatus = Get(values, "OfficialSpecStatus", cfg.OfficialSpecStatus);
            return cfg;
        }

        private static string Get(Dictionary<string, string> values, string key, string fallback)
        {
            string value;
            return values.TryGetValue(key, out value) ? value : fallback;
        }
    }
}
