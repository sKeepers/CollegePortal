using System;
using System.Collections.Generic;
using System.Globalization;
using System.Linq;
using System.Security.Cryptography;
using System.Text;

namespace CollegePortal.Gateway
{
    public class SecurityValidator
    {
        private readonly GatewayConfig _config;
        private readonly NonceStore _nonceStore;
        private readonly RateLimiter _rateLimiter;

        public SecurityValidator(GatewayConfig config, NonceStore nonceStore, RateLimiter rateLimiter)
        {
            _config = config;
            _nonceStore = nonceStore;
            _rateLimiter = rateLimiter;
        }

        public ValidationResult Validate(string method, string path, string remoteIp, IDictionary<string, string> headers, byte[] body, bool requireSignature)
        {
            if (_config.AllowedPortalIps.Length > 0 && !_config.AllowedPortalIps.Contains(remoteIp)) return ValidationResult.Fail("ip_denied", "IP address is not allowlisted.");
            if (!_rateLimiter.Allow(remoteIp)) return ValidationResult.Fail("rate_limited", "Too many requests.");
            if (body != null && body.Length > _config.MaxBodyBytes) return ValidationResult.Fail("request_too_large", "Request body is too large.");
            if (!requireSignature) return ValidationResult.Ok();
            var timestamp = Header(headers, "X-Gateway-Timestamp", "X-FIS-Timestamp");
            var nonce = Header(headers, "X-Gateway-Nonce", "X-FIS-Nonce");
            var bodySha = Header(headers, "X-Gateway-Body-SHA256", "X-FIS-Body-SHA256");
            var signature = Header(headers, "X-Gateway-Signature", "X-FIS-Signature");
            var requestId = Header(headers, "X-Gateway-Request-Id", "X-FIS-Request-Id");
            if (Empty(timestamp) || Empty(nonce) || Empty(bodySha) || Empty(signature) || Empty(requestId)) return ValidationResult.Fail("auth_required", "Missing HMAC headers.");
            DateTime ts;
            if (!DateTime.TryParseExact(timestamp, "yyyy-MM-ddTHH:mm:ssZ", CultureInfo.InvariantCulture, DateTimeStyles.AssumeUniversal | DateTimeStyles.AdjustToUniversal, out ts)) return ValidationResult.Fail("invalid_timestamp", "Timestamp format is invalid.");
            if (Math.Abs((DateTime.UtcNow - ts).TotalSeconds) > _config.RequestWindowSeconds) return ValidationResult.Fail("expired_timestamp", "Request timestamp is outside allowed window.");
            var bodyHash = HexSha256(body ?? new byte[0]);
            if (!ConstantTimeEquals(bodyHash, bodySha)) return ValidationResult.Fail("body_hash_mismatch", "Body SHA-256 does not match.");
            var canonical = method.ToUpperInvariant() + "\n" + path + "\n" + timestamp + "\n" + nonce + "\n" + bodyHash;
            var expected = Hmac(canonical, _config.SharedSecret ?? "");
            if (!ConstantTimeEquals(expected, signature)) return ValidationResult.Fail("invalid_hmac", "HMAC signature is invalid.");
            if (!_nonceStore.TryUse(nonce, ts)) return ValidationResult.Fail("reused_nonce", "Nonce was already used.");
            return ValidationResult.Ok();
        }

        private static string Header(IDictionary<string, string> headers, string primary, string legacy)
        {
            string value;
            if (headers.TryGetValue(primary, out value)) return value;
            return headers.TryGetValue(legacy, out value) ? value : null;
        }

        private static bool Empty(string value) { return string.IsNullOrEmpty(value); }
        public static string HexSha256(byte[] bytes) { using (var sha = SHA256.Create()) return BitConverter.ToString(sha.ComputeHash(bytes)).Replace("-", "").ToLowerInvariant(); }
        public static string Hmac(string canonical, string secret) { using (var hmac = new HMACSHA256(Encoding.UTF8.GetBytes(secret))) return Convert.ToBase64String(hmac.ComputeHash(Encoding.UTF8.GetBytes(canonical))); }
        public static bool ConstantTimeEquals(string a, string b) { var aa = Encoding.UTF8.GetBytes(a ?? ""); var bb = Encoding.UTF8.GetBytes(b ?? ""); var diff = aa.Length ^ bb.Length; for (var i = 0; i < Math.Min(aa.Length, bb.Length); i++) diff |= aa[i] ^ bb[i]; return diff == 0; }
    }

    public class ValidationResult
    {
        public bool Success;
        public string Code;
        public string Message;
        public static ValidationResult Ok() { return new ValidationResult { Success = true }; }
        public static ValidationResult Fail(string code, string message) { return new ValidationResult { Success = false, Code = code, Message = message }; }
    }
}
