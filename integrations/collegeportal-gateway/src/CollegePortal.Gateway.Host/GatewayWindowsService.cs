using System.ServiceProcess;

namespace CollegePortal.Gateway
{
    public class GatewayWindowsService : ServiceBase
    {
        private readonly GatewayConfig _config;
        private GatewayServer _server;
        public GatewayWindowsService(GatewayConfig config) { _config = config; ServiceName = "CollegePortalGateway"; }
        protected override void OnStart(string[] args) { _server = new GatewayServer(_config); _server.Start(); }
        protected override void OnStop() { if (_server != null) _server.Dispose(); }
    }
}
