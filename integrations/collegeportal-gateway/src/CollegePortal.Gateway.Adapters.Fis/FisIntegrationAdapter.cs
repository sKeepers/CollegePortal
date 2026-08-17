using System;
using System.Text.RegularExpressions;

namespace CollegePortal.Gateway
{
    /// <summary>
    /// Адаптер ФИС ГИА и Приёма.
    ///
    /// Карта методов получена замером живого контура 18.08.2026 и записана в
    /// `docs/FIS_SERVICE_MAP.md`. Метод выбирается **адресом**, а не именем
    /// операции: имён операций у этого сервиса нет вовсе.
    /// </summary>
    public class FisIntegrationAdapter : IIntegrationAdapter
    {
        private readonly GatewayConfig _config;
        private readonly FisXmlClient _fis;

        public FisIntegrationAdapter(GatewayConfig config)
        {
            _config = config;
            _fis = new FisXmlClient(config);
        }

        public string Name { get { return "fis"; } }
        public string Version { get { return _config.ServiceVersion; } }
        public GatewayPayload HealthCheck() { return _fis.ZkspdCheck(); }

        public string GetCapabilitiesJson()
        {
            return "[{\"code\":\"zkspd_check\",\"enabled\":true},"
                + "{\"code\":\"test_service_check\",\"enabled\":true},"
                + "{\"code\":\"dictionaries_list\",\"enabled\":true},"
                + "{\"code\":\"dictionaries_details\",\"enabled\":true},"
                + "{\"code\":\"institution_info\",\"enabled\":true},"
                + "{\"code\":\"check_application\",\"enabled\":true},"
                + "{\"code\":\"validate\",\"enabled\":false,\"reason\":\"official_application_xsd_missing\"},"
                + "{\"code\":\"import\",\"enabled\":false,\"reason\":\"disabled_until_official_contract_verified\"},"
                + "{\"code\":\"import_result\",\"enabled\":false,\"reason\":\"address_unknown\"},"
                + "{\"code\":\"production\",\"enabled\":false,\"reason\":\"production_disabled\"}]";
        }

        public GatewayPayload ExecuteReadOnly(string operation, string bodyJson)
        {
            if (operation == "zkspd_check" || operation == "test_service_check") return _fis.ZkspdCheck();
            if (operation == "dictionaries_list") return _fis.Call("dictionary", "");
            if (operation == "institution_info") return _fis.Call("institutioninfo", "");

            if (operation == "dictionaries_details") {
                var code = DictionaryCode(bodyJson);

                // Раньше выбранный оператором справочник до ФИС не доходил вовсе:
                // тело запроса адаптер не читал, и всегда возвращался один и тот же
                // ответ. Человек выбирал одно, получал другое, и портал молчал.
                if (code.Length == 0) {
                    return GatewayPayload.Fail("dictionary_code_required", 0, "Body must carry \"code\": the FIS method requires GetDictionaryContent/DictionaryCode.");
                }

                return _fis.Call("dictionarydetails", "<GetDictionaryContent><DictionaryCode>" + code + "</DictionaryCode></GetDictionaryContent>");
            }

            // Структура `GetResultCheckApplication` замером не выяснена: сервис
            // назовёт недостающее сам, и это честнее, чем угадать её здесь.
            if (operation == "check_application") return _fis.Call("checkapplication", "");

            return GatewayPayload.Fail("unsupported_operation", 0, "FIS adapter read-only operation is not supported.");
        }

        public GatewayPayload ExecuteCommand(string operation, string bodyJson)
        {
            if (operation == "validate" || operation == "import" || operation == "import-result" || operation == "import_result") return GatewayPayload.Fail("operation_disabled", 0, operation + " is disabled: sending data to FIS is a separate decision.");
            if (operation == "production") return GatewayPayload.Fail("production_disabled", 0, "FIS production endpoint is hard-disabled in CollegePortal Gateway.");
            return GatewayPayload.Fail("unsupported_operation", 0, "FIS adapter command is not supported.");
        }

        /// <summary>
        /// Код справочника из тела запроса портала. Разбор регулярным выражением,
        /// а не библиотекой JSON: в теле ровно одно поле, а тащить сериализатор в
        /// службу ради него незачем. Берутся только цифры — иначе значение поехало
        /// бы в XML запроса как есть.
        /// </summary>
        private static string DictionaryCode(string bodyJson)
        {
            if (string.IsNullOrEmpty(bodyJson)) return "";

            var match = Regex.Match(bodyJson, "\"code\"\\s*:\\s*\"?(\\d{1,6})\"?");

            return match.Success ? match.Groups[1].Value : "";
        }

        /// <summary>
        /// Из диагностики вычищается не только общий секрет портала и шлюза, но и
        /// пароль ФИС: он есть в конфиге, а диагностика уходит в портал.
        /// </summary>
        public string RedactDiagnosticData(string value)
        {
            if (value == null) return "";

            value = Redact(value, _config.SharedSecret);
            value = Redact(value, _config.FisSecret);

            return value;
        }

        private static string Redact(string value, string secret)
        {
            return string.IsNullOrEmpty(secret) ? value : value.Replace(secret, "[redacted]");
        }
    }
}
