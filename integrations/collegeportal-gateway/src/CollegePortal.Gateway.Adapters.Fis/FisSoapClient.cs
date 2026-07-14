using System;
using System.IO;
using System.Net;
using System.Text;
using System.Xml;

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
            var start = DateTime.UtcNow;

            try {
                var request = (HttpWebRequest)WebRequest.Create(_config.FisTestEndpoint);
                request.Method = "GET";
                request.Timeout = _config.ConnectTimeoutSeconds * 1000;

                using (var response = (HttpWebResponse)request.GetResponse()) {
                    return GatewayPayload.Success("zkspd_reachable", Elapsed(start), "HTTP " + (int)response.StatusCode);
                }
            } catch (Exception ex) {
                return GatewayPayload.Failure("zkspd_unreachable", Elapsed(start), ex.GetType().Name);
            }
        }

        public GatewayPayload CallReadOnly(string methodName, string innerXml)
        {
            var action = "http://tempuri.org/IImportService/" + methodName;
            var envelope = "<?xml version=\"1.0\" encoding=\"utf-8\"?>"
                + "<s:Envelope xmlns:s=\"http://schemas.xmlsoap.org/soap/envelope/\">"
                + "<s:Body>"
                + "<" + methodName + " xmlns=\"http://tempuri.org/\">"
                + (innerXml ?? "")
                + "</" + methodName + ">"
                + "</s:Body>"
                + "</s:Envelope>";
            var bytes = Encoding.UTF8.GetBytes(envelope);
            var start = DateTime.UtcNow;

            try {
                var request = (HttpWebRequest)WebRequest.Create(_config.FisTestEndpoint);
                request.Method = "POST";
                request.ContentType = "text/xml; charset=utf-8";
                request.Headers.Add("SOAPAction", "\"" + action + "\"");
                request.Timeout = _config.RequestTimeoutSeconds * 1000;
                request.ContentLength = bytes.Length;

                using (var stream = request.GetRequestStream()) {
                    stream.Write(bytes, 0, bytes.Length);
                }

                using (var response = (HttpWebResponse)request.GetResponse())
                using (var reader = new StreamReader(response.GetResponseStream(), Encoding.UTF8)) {
                    return GatewayPayload.Success("soap_ok", Elapsed(start), SummarizeSoap(reader.ReadToEnd()));
                }
            } catch (WebException ex) {
                return GatewayPayload.Failure("soap_fault", Elapsed(start), ex.Status + ": " + ex.GetType().Name);
            } catch (Exception ex) {
                return GatewayPayload.Failure("soap_error", Elapsed(start), ex.GetType().Name);
            }
        }

        private static int Elapsed(DateTime start)
        {
            return (int)(DateTime.UtcNow - start).TotalMilliseconds;
        }

        private static string SummarizeSoap(string xml)
        {
            try {
                var doc = new XmlDocument();
                doc.LoadXml(xml);
                return doc.DocumentElement == null ? "empty" : doc.DocumentElement.Name;
            } catch {
                return "non_xml_response";
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
            return new GatewayPayload { Ok = true, Code = code, LatencyMs = latency, Message = message };
        }

        public static GatewayPayload Failure(string code, int latency, string message)
        {
            return new GatewayPayload { Ok = false, Code = code, LatencyMs = latency, Message = message };
        }
    }
}
