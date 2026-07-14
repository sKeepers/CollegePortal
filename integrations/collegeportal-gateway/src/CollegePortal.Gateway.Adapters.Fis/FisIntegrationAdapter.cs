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
            return "[{\"code\":\"zkspd_tcp_check\",\"enabled\":true},"
                + "{\"code\":\"test_service_check\",\"enabled\":true,\"mode\":\"tcp_only\"},"
                + "{\"code\":\"dictionaries_list\",\"enabled\":false,\"reason\":\"official_wsdl_missing\"},"
                + "{\"code\":\"dictionaries_details\",\"enabled\":false,\"reason\":\"official_wsdl_missing\"},"
                + "{\"code\":\"institution_info\",\"enabled\":false,\"reason\":\"official_wsdl_missing\"},"
                + "{\"code\":\"check_application\",\"enabled\":false,\"reason\":\"official_wsdl_missing\"},"
                + "{\"code\":\"validate\",\"enabled\":false,\"reason\":\"official_application_xsd_missing\"},"
                + "{\"code\":\"import\",\"enabled\":false,\"reason\":\"disabled_until_official_contract_verified\"},"
                + "{\"code\":\"import_result\",\"enabled\":false,\"reason\":\"disabled_until_official_contract_verified\"},"
                + "{\"code\":\"production\",\"enabled\":false,\"reason\":\"production_disabled\"}]";
        }

        public GatewayPayload ExecuteReadOnly(string operation, string bodyJson)
        {
            if (operation == "zkspd_check" || operation == "test_service_check") return _soap.ZkspdCheck();
            return GatewayPayload.Fail("operation_disabled", 0,
                "FIS read-only SOAP operations are disabled until the official WSDL is parsed and verified.");
        }

        public GatewayPayload ExecuteCommand(string operation, string bodyJson)
        {
            if (operation == "validate" || operation == "import" || operation == "import-result" || operation == "import_result") return GatewayPayload.Fail("operation_disabled", 0, operation + " is disabled until the official TEST contract is verified.");
            if (operation == "production") return GatewayPayload.Fail("production_disabled", 0, "FIS production endpoint is hard-disabled in CollegePortal Gateway.");
            return GatewayPayload.Fail("unsupported_operation", 0, "FIS adapter command is not supported.");
        }

        public string RedactDiagnosticData(string value)
        {
            if (value == null) return "";
            var secret = _config.SharedSecret ?? "";
            return secret.Length == 0 ? value : value.Replace(secret, "[redacted]");
        }
    }
}
