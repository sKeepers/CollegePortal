using System;
using System.Collections.Generic;
using System.IO;
using System.Text;

namespace CollegePortal.Gateway.Tests
{
    internal static class GatewaySecurityTests
    {
        private const string Secret = "gateway-test-secret-with-at-least-32-characters";

        public static int Main()
        {
            var root = Path.Combine(Path.GetTempPath(), "collegeportal-gateway-tests-" + Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(root);
            try {
                ValidHmacAndReplay(root);
                InvalidHmacDoesNotConsumeNonce(root);
                ExpiredTimestampIsRejected(root);
                ProductionEndpointIsRejectedWithoutNetworkCall();
                FisCapabilitiesUseXmlHttpStopGate();
                StartupDiagnosticsClassifiesKnownFailures();
                StartupDiagnosticsRedactsRegisteredSecret();
                Console.WriteLine("[OK] Gateway security tests passed.");
                return 0;
            }
            catch (Exception exception) {
                Console.Error.WriteLine("[FAIL] " + exception.Message);
                return 1;
            }
            finally { if (Directory.Exists(root)) Directory.Delete(root, true); }
        }

        private static void ValidHmacAndReplay(string root)
        {
            var fixture = Fixture(root, "valid");
            var headers = Headers("GET", "/diagnostics/latest", "nonce-valid", DateTime.UtcNow, Secret);
            Assert(fixture.Validator.Validate("GET", "/diagnostics/latest", "127.0.0.1", headers, new byte[0], true).Success,
                "Valid HMAC was rejected.");
            var replay = fixture.Validator.Validate("GET", "/diagnostics/latest", "127.0.0.1", headers, new byte[0], true);
            Assert(!replay.Success && replay.Code == "reused_nonce", "Repeated nonce was not rejected.");
        }

        private static void InvalidHmacDoesNotConsumeNonce(string root)
        {
            var fixture = Fixture(root, "invalid");
            var timestamp = DateTime.UtcNow;
            var invalid = Headers("GET", "/diagnostics/latest", "nonce-not-consumed", timestamp, Secret);
            invalid["X-Gateway-Signature"] = "invalid";
            var rejected = fixture.Validator.Validate("GET", "/diagnostics/latest", "127.0.0.1", invalid, new byte[0], true);
            Assert(!rejected.Success && rejected.Code == "invalid_hmac", "Invalid HMAC was not rejected.");
            var valid = Headers("GET", "/diagnostics/latest", "nonce-not-consumed", timestamp, Secret);
            Assert(fixture.Validator.Validate("GET", "/diagnostics/latest", "127.0.0.1", valid, new byte[0], true).Success,
                "Invalid HMAC consumed the nonce before authentication.");
        }

        private static void ExpiredTimestampIsRejected(string root)
        {
            var fixture = Fixture(root, "expired");
            var headers = Headers("GET", "/diagnostics/latest", "nonce-expired", DateTime.UtcNow.AddHours(-1), Secret);
            var result = fixture.Validator.Validate("GET", "/diagnostics/latest", "127.0.0.1", headers, new byte[0], true);
            Assert(!result.Success && result.Code == "expired_timestamp", "Expired timestamp was not rejected.");
        }

        private static void ProductionEndpointIsRejectedWithoutNetworkCall()
        {
            var config = new GatewayConfig { FisTestEndpoint = "http://10.0.3.1:8080/api/import/importservice.svc" };
            var result = new FisXmlHttpClient(config).ZkspdCheck();
            Assert(!result.Ok && result.Code == "test_endpoint_not_allowed", "Production FIS endpoint was not rejected.");
        }

        private static void FisCapabilitiesUseXmlHttpStopGate()
        {
            var json = new FisIntegrationAdapter(new GatewayConfig()).GetCapabilitiesJson();
            Assert(json.Contains("xml_over_http"), "FIS capabilities do not expose XML-over-HTTP protocol.");
            Assert(!json.Contains("official_wsdl_missing"), "FIS capabilities still wait for a WSDL that the official protocol does not use.");
            Assert(!json.Contains("SOAP"), "FIS capabilities still expose SOAP wording.");
        }
        private static void StartupDiagnosticsClassifiesKnownFailures()
        {
            Assert(StartupDiagnostics.Classify(new PlatformNotSupportedException()) == "UNSUPPORTED_RUNTIME",
                "PlatformNotSupportedException startup code is incorrect.");
            Assert(StartupDiagnostics.Classify(new BadImageFormatException()) == "ASSEMBLY_LOAD_FAILED",
                "BadImageFormatException startup code is incorrect.");
            Assert(StartupDiagnostics.Classify(new GatewayStartupException("CONFIG_NOT_FOUND", "missing")) == "CONFIG_NOT_FOUND",
                "Explicit startup code was not preserved.");
        }

        private static void StartupDiagnosticsRedactsRegisteredSecret()
        {
            const string sensitive = "diagnostics-secret-value-never-log";
            StartupDiagnostics.RegisterSensitiveValue(sensitive);
            var redacted = StartupDiagnostics.Redact("prefix " + sensitive + " suffix");
            Assert(!redacted.Contains(sensitive) && redacted.Contains("[REDACTED]"),
                "Startup diagnostics did not redact a registered secret.");
        }

        private static FixtureData Fixture(string root, string name)
        {
            var config = new GatewayConfig {
                AllowedPortalIps = new[] { "127.0.0.1" },
                SharedSecret = Secret,
                RequestWindowSeconds = 300,
                RateLimitPerMinute = 100
            };
            return new FixtureData {
                Validator = new SecurityValidator(config,
                    new NonceStore(Path.Combine(root, name + "-nonces.txt"), 300),
                    new RateLimiter(100))
            };
        }

        private static Dictionary<string, string> Headers(string method, string path, string nonce, DateTime timestamp, string secret)
        {
            var body = new byte[0];
            var bodyHash = SecurityValidator.HexSha256(body);
            var formatted = timestamp.ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ssZ");
            var canonical = method + "\n" + path + "\n" + formatted + "\n" + nonce + "\n" + bodyHash;
            return new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase) {
                { "X-Gateway-Timestamp", formatted },
                { "X-Gateway-Nonce", nonce },
                { "X-Gateway-Request-Id", Guid.NewGuid().ToString("N") },
                { "X-Gateway-Body-SHA256", bodyHash },
                { "X-Gateway-Signature", SecurityValidator.Hmac(canonical, secret) }
            };
        }

        private static void Assert(bool condition, string message) { if (!condition) throw new InvalidOperationException(message); }
        private sealed class FixtureData { public SecurityValidator Validator; }
    }
}
