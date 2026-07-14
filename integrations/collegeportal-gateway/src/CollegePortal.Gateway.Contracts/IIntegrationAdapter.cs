namespace CollegePortal.Gateway
{
    public interface IIntegrationAdapter
    {
        string Name { get; }
        string Version { get; }
        GatewayPayload HealthCheck();
        string GetCapabilitiesJson();
        GatewayPayload ExecuteReadOnly(string operation, string bodyJson);
        GatewayPayload ExecuteCommand(string operation, string bodyJson);
        string RedactDiagnosticData(string value);
    }
}
