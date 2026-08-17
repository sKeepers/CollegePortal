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
    /// Файл был записан одной строкой с потерянным экранированием и не
    /// компилировался: <c>var envelope="&lt;?xml version="1.0" ..."</c> и
    /// <c>Headers.Add("SOAPAction","""+action+""")</c> кодом на C# не являются.
    /// Восстановлен по замыслу и дополнен блоком авторизации.
    ///
    /// Форма запроса взята из контракта на узле
    /// (<c>specs\fis\active\import-service-wrapper.xsd</c>), а не угадана:
    ///
    /// * боевые операции — <c>GetDictionariesList</c>, <c>GetDictionaryDetails</c>,
    ///   <c>GetInstitutionInfo</c> — принимают необязательный элемент
    ///   <c>data</c> с произвольным XML внутри (<c>xs:any processContents="lax"</c>);
    /// * тестовые — <c>GetTestDictionariesList</c>, <c>GetTestDictionaryDetails</c>,
    ///   <c>GetTestCheckApplication</c> — не принимают **ничего**
    ///   (<c>xs:sequence/</c>), и пустое тело операции для них верно.
    ///
    /// Учётные данные передаются полем внутри тела запроса: официальная XSD 4.9
    /// описывает <c>Root/AuthData</c> с <c>Login</c>, <c>Pass</c> и необязательным
    /// <c>InstitutionID</c>. Ни WS-Security, ни клиентского сертификата: канал
    /// защищён ЗКСПД и ViPNet.
    /// </summary>
    public class FisSoapClient
    {
        private const string ServiceNamespace = "http://tempuri.org/";
        private const string PortTypeName = "IImportService";

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
            }
            catch (Exception ex) {
                return GatewayPayload.Fail("zkspd_unreachable", Elapsed(start), ex.GetType().Name);
            }
        }

        /// <summary>
        /// Вызов операции без параметров — для тестовых операций контракта.
        /// </summary>
        public GatewayPayload CallReadOnly(string methodName)
        {
            return Call(methodName, "");
        }

        /// <summary>
        /// Вызов операции с блоком авторизации.
        ///
        /// <paramref name="extraXml"/> кладётся внутрь <c>Root</c> следом за
        /// <c>AuthData</c> — туда идут параметры операции, если они у неё есть.
        ///
        /// Без учётных данных запрос **не отправляется**: неавторизованный вызов
        /// в государственную систему вернёт отказ, который потом будут искать в
        /// сети и настройках. Лучше сказать прямо, чего не хватает.
        /// </summary>
        public GatewayPayload CallAuthenticated(string methodName, string extraXml)
        {
            if (string.IsNullOrEmpty(_config.FisLogin) || string.IsNullOrEmpty(_config.FisSecret)) {
                return GatewayPayload.Fail("fis_credentials_missing", 0,
                    "FisLogin and FisSecret are not set in gateway.private.config: the operation requires AuthData.");
            }

            return Call(methodName, "<data>" + AuthenticatedRoot(extraXml) + "</data>");
        }

        /// <summary>
        /// `Root` объявлен в схеме **без целевого пространства имён**, а элемент
        /// `data` — в `http://tempuri.org/`. Поэтому `xmlns=""` обязателен: без
        /// него `Root` унаследует пространство операции, и ФИС не узнает блок
        /// авторизации. Ошибка тихая — вызов уйдёт и вернёт отказ.
        /// </summary>
        private string AuthenticatedRoot(string extraXml)
        {
            var builder = new StringBuilder();
            builder.Append("<Root xmlns=\"\"><AuthData>");
            builder.Append("<Login>").Append(Escape(_config.FisLogin)).Append("</Login>");
            builder.Append("<Pass>").Append(Escape(_config.FisSecret)).Append("</Pass>");

            if (!string.IsNullOrEmpty(_config.FisInstitutionId)) {
                builder.Append("<InstitutionID>").Append(Escape(_config.FisInstitutionId)).Append("</InstitutionID>");
            }

            builder.Append("</AuthData>");
            builder.Append(extraXml ?? "");
            builder.Append("</Root>");

            return builder.ToString();
        }

        private GatewayPayload Call(string methodName, string innerXml)
        {
            var action = ServiceNamespace + PortTypeName + "/" + methodName;
            var envelope = "<?xml version=\"1.0\" encoding=\"utf-8\"?>"
                + "<s:Envelope xmlns:s=\"http://schemas.xmlsoap.org/soap/envelope/\"><s:Body>"
                + "<" + methodName + " xmlns=\"" + ServiceNamespace + "\">"
                + (innerXml ?? "")
                + "</" + methodName + ">"
                + "</s:Body></s:Envelope>";

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
            }
            catch (WebException ex) {
                return GatewayPayload.Fail("soap_fault", Elapsed(start), ex.Status + ": " + ex.GetType().Name);
            }
            catch (Exception ex) {
                return GatewayPayload.Fail("soap_error", Elapsed(start), ex.GetType().Name);
            }
        }

        private static int Elapsed(DateTime start)
        {
            return (int)(DateTime.UtcNow - start).TotalMilliseconds;
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

        private static string SummarizeSoap(string xml)
        {
            try {
                var document = new XmlDocument();
                document.LoadXml(xml);
                return document.DocumentElement == null ? "empty" : document.DocumentElement.Name;
            }
            catch {
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

        // Фабрика называется `Success`, а не `Ok`: поле и метод с одним именем в
        // одном типе C# не разрешает (CS0102). В прежней редакции были и поле
        // `Ok`, и метод `Ok` — ещё одна причина, по которой эти исходники не
        // собирались никогда.
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
