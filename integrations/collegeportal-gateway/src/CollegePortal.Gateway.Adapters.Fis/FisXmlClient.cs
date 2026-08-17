using System;
using System.IO;
using System.Net;
using System.Text;
using System.Xml;

namespace CollegePortal.Gateway
{
    /// <summary>
    /// Клиент сервиса автоматизированного взаимодействия ФИС ГИА и Приёма.
    ///
    /// **Это не SOAP.** Ответ ФЦТ от 17.07.2026: сервис не является
    /// SOAP-веб-сервисом и WSDL-контракта не предоставляет — это XML-шлюз,
    /// `HTTP POST` с XML-телом. Подтверждено замером живого контура 18.08.2026.
    ///
    /// Отсюда форма запроса: ни конверта, ни `SOAPAction`, ни имени операции в
    /// теле. **Метод выбирается адресом**, а тело — обычный XML:
    ///
    /// <code>
    /// &lt;Root&gt;
    ///   &lt;AuthData&gt;&lt;Login/&gt;&lt;Pass/&gt;&lt;/AuthData&gt;
    ///   &lt;!-- дополнительный тег, если метод его требует --&gt;
    /// &lt;/Root&gt;
    /// </code>
    ///
    /// Предыдущая редакция собирала конверт SOAP по `portType` из WSDL. Тот WSDL
    /// оказался заготовкой платформы: `wsdl:binding` — ноль, `wsdl:port` — ноль,
    /// адресов нет, и семнадцать объявленных операций в запросах не участвуют.
    ///
    /// **Сервис сам называет недостающее.** Отказ приходит с `HTTP 200` и телом
    /// `Error/ErrorText`, где написано, какого тега не хватает, а на неверный
    /// дочерний элемент — со списком ожидаемых. Уточнять структуру надо этим, а
    /// не догадками.
    /// </summary>
    public class FisXmlClient
    {
        private readonly GatewayConfig _config;

        public FisXmlClient(GatewayConfig config)
        {
            _config = config;
        }

        /// <summary>
        /// Достижимость ЗКСПД: обычный GET по адресу службы. До вызова метода
        /// дело не доходит — проверяется канал, а не ФИС.
        /// </summary>
        public GatewayPayload ZkspdCheck()
        {
            var start = DateTime.UtcNow;
            try {
                var request = (HttpWebRequest)WebRequest.Create(BaseAddress());
                request.Method = "GET";
                request.Timeout = _config.ConnectTimeoutSeconds * 1000;
                using (var response = (HttpWebResponse)request.GetResponse()) {
                    return GatewayPayload.Success("zkspd_reachable", Elapsed(start), "HTTP " + (int)response.StatusCode);
                }
            }
            catch (Exception ex) {
                return GatewayPayload.Fail("zkspd_unreachable", Elapsed(start), ex.GetType().Name);
            }
        }

        /// <summary>
        /// Вызов метода. <paramref name="method"/> — суффикс адреса
        /// (`dictionary`, `institutioninfo`, …), <paramref name="extraXml"/> —
        /// то, что метод требует сверх блока авторизации.
        ///
        /// Без учётных данных запрос **не отправляется**: неавторизованный вызов
        /// в государственную систему вернёт отказ, который потом будут искать в
        /// сети и настройках.
        /// </summary>
        public GatewayPayload Call(string method, string extraXml)
        {
            if (string.IsNullOrEmpty(_config.FisLogin) || string.IsNullOrEmpty(_config.FisSecret)) {
                return GatewayPayload.Fail("fis_credentials_missing", 0,
                    "FisLogin and FisSecret are not set in gateway.private.config: every method requires AuthData.");
            }

            var body = new StringBuilder();
            body.Append("<?xml version=\"1.0\" encoding=\"utf-8\"?><Root><AuthData>");
            body.Append("<Login>").Append(Escape(_config.FisLogin)).Append("</Login>");
            body.Append("<Pass>").Append(Escape(_config.FisSecret)).Append("</Pass>");

            if (!string.IsNullOrEmpty(_config.FisInstitutionId)) {
                body.Append("<InstitutionID>").Append(Escape(_config.FisInstitutionId)).Append("</InstitutionID>");
            }

            body.Append("</AuthData>");
            body.Append(extraXml ?? "");
            body.Append("</Root>");

            return Post(BaseAddress() + "/" + method, body.ToString());
        }

        private string BaseAddress()
        {
            return (_config.FisTestEndpoint ?? "").TrimEnd('/');
        }

        private GatewayPayload Post(string url, string xml)
        {
            var bytes = Encoding.UTF8.GetBytes(xml);
            var start = DateTime.UtcNow;

            try {
                var request = (HttpWebRequest)WebRequest.Create(url);
                request.Method = "POST";
                request.ContentType = "text/xml; charset=utf-8";
                request.Timeout = _config.RequestTimeoutSeconds * 1000;
                request.ContentLength = bytes.Length;

                using (var stream = request.GetRequestStream()) {
                    stream.Write(bytes, 0, bytes.Length);
                }

                using (var response = (HttpWebResponse)request.GetResponse())
                using (var reader = new StreamReader(response.GetResponseStream(), Encoding.UTF8)) {
                    return Interpret(reader.ReadToEnd(), Elapsed(start));
                }
            }
            catch (WebException ex) {
                return GatewayPayload.Fail("fis_http_error", Elapsed(start), Describe(ex));
            }
            catch (Exception ex) {
                return GatewayPayload.Fail("fis_transport_error", Elapsed(start), ex.GetType().Name);
            }
        }

        /// <summary>
        /// Отказ ФИС приходит с `HTTP 200` и телом `Error`, а не кодом ответа.
        /// Поэтому успех определяется корневым элементом, а не статусом: иначе
        /// «не найден тег PackageData» выглядело бы удачным вызовом.
        /// </summary>
        private static GatewayPayload Interpret(string xml, int elapsed)
        {
            var document = new XmlDocument();

            try {
                document.LoadXml(xml);
            }
            catch {
                return GatewayPayload.Fail("fis_non_xml_response", elapsed, Shorten(xml));
            }

            var root = document.DocumentElement;

            if (root == null) {
                return GatewayPayload.Fail("fis_empty_response", elapsed, "");
            }

            if (root.LocalName == "Error") {
                var code = Text(root, "ErrorCode");
                var text = Text(root, "ErrorText");

                return GatewayPayload.Fail("fis_error", elapsed, (code.Length == 0 ? "" : "[" + code + "] ") + text);
            }

            var payload = GatewayPayload.Success("fis_ok", elapsed, root.LocalName + ", " + xml.Length + " bytes");
            payload.Data = xml;

            return payload;
        }

        private static string Text(XmlElement root, string name)
        {
            var nodes = root.GetElementsByTagName(name);

            return nodes.Count == 0 ? "" : (nodes[0].InnerText ?? "").Trim();
        }

        private static string Shorten(string value)
        {
            value = (value ?? "").Replace("\r", " ").Replace("\n", " ").Trim();

            return value.Length > 400 ? value.Substring(0, 400) + "…" : value;
        }

        private static int Elapsed(DateTime start)
        {
            return (int)(DateTime.UtcNow - start).TotalMilliseconds;
        }

        /// <summary>
        /// Что именно ответил сервис: статус транспорта, код HTTP и начало тела.
        /// `ex.Status` в одиночку не отвечает ни на один вопрос.
        /// </summary>
        private static string Describe(WebException ex)
        {
            var description = ex.Status.ToString();
            var response = ex.Response as HttpWebResponse;

            if (response == null) {
                return description + ": " + ex.Message;
            }

            description += " HTTP " + (int)response.StatusCode;

            try {
                using (var reader = new StreamReader(response.GetResponseStream(), Encoding.UTF8)) {
                    var body = Shorten(reader.ReadToEnd());

                    return body.Length == 0 ? description : description + ": " + body;
                }
            }
            catch {
                return description;
            }
        }

        private static string Escape(string value)
        {
            if (string.IsNullOrEmpty(value)) return "";

            return value
                .Replace("&", "&amp;")
                .Replace("<", "&lt;")
                .Replace(">", "&gt;")
                .Replace("\"", "&quot;");
        }
    }

    public class GatewayPayload
    {
        public bool Ok;
        public string Code;
        public int LatencyMs;
        public string Message;

        /// <summary>
        /// Ответ ФИС целиком. Раньше наружу уходило только имя корневого элемента,
        /// и данные, ради которых делался вызов, терялись у шлюза.
        /// </summary>
        public string Data;

        // Фабрика называется `Success`, а не `Ok`: поле и метод с одним именем в
        // одном типе C# не разрешает (CS0102).
        public static GatewayPayload Success(string code, int latency, string message)
        {
            return new GatewayPayload { Ok = true, Code = code, LatencyMs = latency, Message = message };
        }

        public static GatewayPayload Fail(string code, int latency, string message)
        {
            return new GatewayPayload { Ok = false, Code = code, LatencyMs = latency, Message = message };
        }
    }
}
