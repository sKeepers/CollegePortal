using System;

namespace CollegePortal.Gateway
{
    public class FisIntegrationAdapter : IIntegrationAdapter
    {
        private readonly GatewayConfig _config;
        private readonly FisSoapClient _soap;

        public FisIntegrationAdapter(GatewayConfig config)
        {
            _config = config;
            _soap = new FisSoapClient(config);
        }

        public string Name { get { return "fis"; } }
        public string Version { get { return _config.ServiceVersion; } }
        public GatewayPayload HealthCheck() { return _soap.ZkspdCheck(); }

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
                + "{\"code\":\"import_result\",\"enabled\":false,\"reason\":\"disabled_until_official_contract_verified\"},"
                + "{\"code\":\"production\",\"enabled\":false,\"reason\":\"production_disabled\"}]";
        }

        public GatewayPayload ExecuteReadOnly(string operation, string bodyJson)
        {
            if (operation == "zkspd_check" || operation == "test_service_check") return _soap.ZkspdCheck();

            // Блок авторизации уходит во **всех** операциях, включая тестовые.
            //
            // По контракту (`import-service-wrapper.xsd`) тестовые операции не
            // принимают ничего — `xs:sequence/`, — и сначала так и было сделано.
            // Живой сервис 18.08.2026 сказал иначе: на пустое тело он отвечает
            // «ошибки валидации XML. Не найден тег AuthData». Контракт и служба
            // расходятся, и права здесь служба.
            if (operation == "dictionaries_list") return _soap.CallAuthenticated("GetTestDictionariesList", "");
            if (operation == "dictionaries_details") return _soap.CallAuthenticated("GetTestDictionaryDetails", "");
            if (operation == "check_application") return _soap.CallAuthenticated("GetTestCheckApplication", "");
            if (operation == "institution_info") return _soap.CallAuthenticated("GetInstitutionInfo", "");
            return GatewayPayload.Fail("unsupported_operation", 0, "FIS adapter read-only operation is not supported.");
        }

        public GatewayPayload ExecuteCommand(string operation, string bodyJson)
        {
            if (operation == "validate" || operation == "import" || operation == "import-result" || operation == "import_result") return GatewayPayload.Fail("operation_disabled", 0, operation + " is disabled until the official TEST contract is verified.");
            if (operation == "production") return GatewayPayload.Fail("production_disabled", 0, "FIS production endpoint is hard-disabled in CollegePortal Gateway.");
            return GatewayPayload.Fail("unsupported_operation", 0, "FIS adapter command is not supported.");
        }

        /// <summary>
        /// Из диагностики вычищается не только общий секрет портала и шлюза, но и
        /// пароль ФИС: он теперь есть в конфиге, а диагностика уходит в портал.
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
