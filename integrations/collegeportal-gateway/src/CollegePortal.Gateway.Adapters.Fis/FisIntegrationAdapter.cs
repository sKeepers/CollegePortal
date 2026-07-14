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
            if (operation == "dictionaries_list") return _soap.CallReadOnly("GetTestDictionariesList", "");
            if (operation == "dictionaries_details") return _soap.CallReadOnly("GetTestDictionaryDetails", "");
            if (operation == "institution_info") return _soap.CallReadOnly("GetInstitutionInfo", "");
            if (operation == "check_application") return _soap.CallReadOnly("GetTestCheckApplication", "");
            return GatewayPayload.Failure("unsupported_operation", 0, "FIS adapter read-only operation is not supported.");
        }

        public GatewayPayload ExecuteCommand(string operation, string bodyJson)
        {
            if (operation == "validate" || operation == "import" || operation == "import-result" || operation == "import_result") return GatewayPayload.Failure("operation_disabled", 0, operation + " is disabled until the official TEST contract is verified.");
            if (operation == "production") return GatewayPayload.Failure("production_disabled", 0, "FIS production endpoint is hard-disabled in CollegePortal Gateway.");
            return GatewayPayload.Failure("unsupported_operation", 0, "FIS adapter command is not supported.");
        }

        public string RedactDiagnosticData(string value)
        {
            if (value == null) return "";
            var secret = _config.SharedSecret ?? "";
            return secret.Length == 0 ? value : value.Replace(secret, "[redacted]");
        }
    }
}
