using System;
using System.Net;

namespace CollegePortal.Gateway
{
    public class FisSoapClient
    {
        private readonly GatewayConfig _config;

        public FisSoapClient(GatewayConfig config)
        {
            _config = config;
        }

        public GatewayPayload ZkspdCheck()
        {
            var started = DateTime.UtcNow;

            try
            {
                var request = (HttpWebRequest)WebRequest.Create(_config.FisTestEndpoint);
                request.Method = "GET";
                request.Timeout = _config.ConnectTimeoutSeconds * 1000;

                using (var response = (HttpWebResponse)request.GetResponse())
                {
                    return GatewayPayload.Success(
                        "zkspd_reachable",
                        (int)(DateTime.UtcNow - started).TotalMilliseconds,
                        "HTTP " + (int)response.StatusCode);
                }
            }
            catch (Exception exception)
            {
                return GatewayPayload.Fail(
                    "zkspd_unreachable",
                    (int)(DateTime.UtcNow - started).TotalMilliseconds,
                    exception.GetType().Name);
            }
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
