using System;
using System.Net.Sockets;

namespace CollegePortal.Gateway
{
    public class FisSoapClient
    {
        private const string AllowedTestHost = "10.0.3.1";
        private const int AllowedTestPort = 8383;
        private const string AllowedTestPath = "/api/import/importservice.svc";
        private readonly GatewayConfig _config;

        public FisSoapClient(GatewayConfig config)
        {
            _config = config;
        }

        public GatewayPayload ZkspdCheck()
        {
            var started = DateTime.UtcNow;
            Uri endpoint;
            if (!Uri.TryCreate(_config.FisTestEndpoint, UriKind.Absolute, out endpoint)
                || endpoint.Scheme != Uri.UriSchemeHttp
                || !string.Equals(endpoint.Host, AllowedTestHost, StringComparison.OrdinalIgnoreCase)
                || endpoint.Port != AllowedTestPort
                || !string.Equals(endpoint.AbsolutePath, AllowedTestPath, StringComparison.OrdinalIgnoreCase))
            {
                return GatewayPayload.Fail("test_endpoint_not_allowed", 0, "Configured endpoint is outside the fixed FIS TEST allowlist.");
            }

            using (var client = new TcpClient())
            {
                try
                {
                    var pending = client.BeginConnect(endpoint.Host, endpoint.Port, null, null);
                    var connected = pending.AsyncWaitHandle.WaitOne(TimeSpan.FromSeconds(_config.ConnectTimeoutSeconds));
                    if (!connected)
                    {
                        return GatewayPayload.Fail("fis_test_tcp_timeout", Elapsed(started), "TCP connection timed out.");
                    }

                    client.EndConnect(pending);
                    return GatewayPayload.Success("fis_test_tcp_reachable", Elapsed(started), "FIS TEST TCP endpoint is reachable.");
                }
                catch (SocketException exception)
                {
                    return GatewayPayload.Fail("fis_test_tcp_unreachable", Elapsed(started), exception.SocketErrorCode.ToString());
                }
                catch (Exception exception)
                {
                    return GatewayPayload.Fail("fis_test_tcp_unreachable", Elapsed(started), exception.GetType().Name);
                }
            }
        }

        private static int Elapsed(DateTime started)
        {
            return (int)(DateTime.UtcNow - started).TotalMilliseconds;
        }
    }

    public class GatewayPayload
    {
        public bool Ok;
        public string Code;
        public int LatencyMs;
        public string Message;

        public static GatewayPayload Success(string code, int latency, string message)
        {
            return new GatewayPayload
            {
                Ok = true,
                Code = code,
                LatencyMs = latency,
                Message = message
            };
        }

        public static GatewayPayload Fail(string code, int latency, string message)
        {
            return new GatewayPayload
            {
                Ok = false,
                Code = code,
                LatencyMs = latency,
                Message = message
            };
        }
    }
}
