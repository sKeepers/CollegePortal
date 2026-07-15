using System.ServiceProcess;

namespace CollegePortal.Gateway
{
    public class GatewayWindowsService : ServiceBase
    {
        private readonly GatewayConfig _config;
        private GatewayServer _server;
        public GatewayWindowsService(GatewayConfig config) { _config = config; ServiceName = "CollegePortalGateway"; }
        protected override void OnStart(string[] args)
        {
            try {
                _server = new GatewayServer(_config);
                _server.Start();
                StartupDiagnostics.Write("service", "running");
            }
            catch (System.Exception exception) {
                StartupDiagnostics.WriteException(StartupDiagnostics.Classify(exception), exception);
                throw;
            }
        }
        protected override void OnStop() { if (_server != null) _server.Dispose(); }
    }
}
