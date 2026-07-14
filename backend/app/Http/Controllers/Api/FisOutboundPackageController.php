<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FisOutboundPackageEventResource;
use App\Http\Resources\FisOutboundPackageResource;
use App\Jobs\RefreshFisPackageStatusJob;
use App\Jobs\SendFisPackageJob;
use App\Models\FisOutboundPackage;
use App\Services\AuditLogService;
use App\Services\FisIntegration\Exceptions\FisIntegrationException;
use App\Services\FisIntegration\FisPackageBuilder;
use App\Services\FisIntegration\FisPackageSender;
use App\Services\FisIntegration\FisPackageStatusService;
use App\Services\FisIntegration\FisPackageValidator;
use App\Services\FisIntegration\GatewayFisTransport;
use App\Services\FisIntegration\FisSpecificationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class FisOutboundPackageController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $packages = FisOutboundPackage::query()
            ->withCount('events')
            ->when($request->string('package_type')->toString(), fn ($query, string $type) => $query->where('package_type', $type))
            ->when($request->string('environment')->toString(), fn ($query, string $env) => $query->where('environment', $env))
            ->when($request->string('status')->toString(), fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate((int) ($request->integer('per_page') ?: 50));

        return FisOutboundPackageResource::collection($packages);
    }

    public function store(Request $request, FisSpecificationRegistry $registry): JsonResponse
    {
        $data = $request->validate([
            'package_type' => ['required','string','max:100'],
            'external_campaign_id' => ['nullable','string','max:100'],
            'source_entity_type' => ['nullable','string','max:100'],
            'source_entity_id' => ['nullable','integer'],
            'schema_version' => ['nullable','string','max:100'],
            'environment' => ['nullable','in:test,production'],
        ]);
        $environment = $data['environment'] ?? 'test';
        if ($environment === 'production' && ! config('fis_api.allow_production_send')) {
            return response()->json(['message' => 'Production FIS packages are blocked until FIS-API-002 controlled activation.'], Response::HTTP_FORBIDDEN);
        }
        $package = FisOutboundPackage::create([
            'package_type' => $data['package_type'],
            'external_campaign_id' => $data['external_campaign_id'] ?? null,
            'source_entity_type' => $data['source_entity_type'] ?? null,
            'source_entity_id' => $data['source_entity_id'] ?? null,
            'schema_version' => $data['schema_version'] ?? $registry->schemaVersion(),
            'environment' => $environment,
            'status' => 'draft',
            'created_by' => $request->user()?->id,
        ]);
        $package->events()->create(['event_type' => 'created', 'status' => 'draft', 'metadata' => ['schema_version' => $package->schema_version], 'user_id' => $request->user()?->id]);
        AuditLogService::log('fis_outbound', 'created', $package, null, ['package_type' => $package->package_type, 'environment' => $package->environment], $request);

        return (new FisOutboundPackageResource($package))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(FisOutboundPackage $package): FisOutboundPackageResource
    {
        return new FisOutboundPackageResource($package->loadCount('events'));
    }

    public function generate(FisOutboundPackage $package, FisPackageBuilder $builder): JsonResponse
    {
        try {
            $package = $builder->generate($package);
            AuditLogService::log('fis_outbound', 'generated', $package, null, ['payload_sha256' => $package->payload_sha256]);
            return response()->json(['data' => new FisOutboundPackageResource($package)]);
        } catch (FisIntegrationException $exception) {
            $package->events()->create(['event_type' => 'generation_blocked', 'status' => $package->status, 'metadata' => ['message' => $exception->getMessage()], 'user_id' => auth()->id()]);
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    public function validatePackage(FisOutboundPackage $package, FisPackageValidator $validator): JsonResponse
    {
        $result = $validator->validate($package);
        AuditLogService::log('fis_outbound', 'validated', $package, null, ['ok' => $result['ok'], 'error_count' => count($result['errors'] ?? [])]);
        return response()->json(['data' => new FisOutboundPackageResource($package->fresh()), 'validation' => $result]);
    }

    public function sendPreview(FisOutboundPackage $package, FisPackageSender $sender): JsonResponse
    {
        try {
            return response()->json($sender->send($package, preview: true, mock: true));
        } catch (FisIntegrationException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    public function send(FisOutboundPackage $package, Request $request): JsonResponse
    {
        if ($package->environment === 'production') {
            return response()->json(['message' => 'Production send is blocked in FIS-API-001. Use FIS-API-002 after certification.'], Response::HTTP_FORBIDDEN);
        }
        SendFisPackageJob::dispatchSync($package->id, (bool) $request->boolean('mock', true));
        return response()->json(['data' => new FisOutboundPackageResource($package->fresh())]);
    }

    public function refreshStatus(FisOutboundPackage $package, Request $request, FisPackageStatusService $service): JsonResponse
    {
        try {
            if ($request->boolean('queue')) {
                RefreshFisPackageStatusJob::dispatch($package->id, (bool) $request->boolean('mock', true));
                return response()->json(['queued' => true]);
            }
            $result = $service->refresh($package, (bool) $request->boolean('mock', true));
            return response()->json(['data' => new FisOutboundPackageResource($package->fresh()), 'status' => $result]);
        } catch (FisIntegrationException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    public function cancel(FisOutboundPackage $package): FisOutboundPackageResource
    {
        $package->update(['status' => 'cancelled']);
        $package->events()->create(['event_type' => 'cancelled', 'status' => 'cancelled', 'metadata' => [], 'user_id' => auth()->id()]);
        AuditLogService::log('fis_outbound', 'cancelled', $package, null, ['status' => 'cancelled']);
        return new FisOutboundPackageResource($package->fresh());
    }

    public function events(FisOutboundPackage $package): AnonymousResourceCollection
    {
        return FisOutboundPackageEventResource::collection($package->events()->orderByDesc('created_at')->get());
    }


    public function gatewayHealth(GatewayFisTransport $gateway): JsonResponse
    {
        return $this->gatewayResponse(fn () => $gateway->health());
    }

    public function gatewayVersion(GatewayFisTransport $gateway): JsonResponse
    {
        return $this->gatewayResponse(fn () => $gateway->version());
    }


    public function gatewayCapabilities(GatewayFisTransport $gateway): JsonResponse
    {
        return $this->gatewayResponse(fn () => $gateway->capabilities());
    }

    public function gatewayAdapters(GatewayFisTransport $gateway): JsonResponse
    {
        return $this->gatewayResponse(fn () => $gateway->listAdapters());
    }

    public function gatewayFisAdapterHealth(GatewayFisTransport $gateway): JsonResponse
    {
        return $this->gatewayResponse(fn () => $gateway->adapterHealth());
    }

    public function gatewayDiagnostics(GatewayFisTransport $gateway): JsonResponse
    {
        return $this->gatewayResponse(fn () => $gateway->diagnostics());
    }

    public function gatewayZkspdCheck(GatewayFisTransport $gateway): JsonResponse
    {
        return $this->gatewayResponse(fn () => $gateway->zkspdCheck());
    }

    public function gatewayDictionariesList(GatewayFisTransport $gateway): JsonResponse
    {
        return $this->gatewayResponse(fn () => $gateway->dictionariesList());
    }

    public function gatewayDictionaryDetails(Request $request, GatewayFisTransport $gateway): JsonResponse
    {
        return $this->gatewayResponse(fn () => $gateway->dictionaryDetails($request->string('code')->toString() ?: null));
    }

    public function gatewayInstitutionInfo(GatewayFisTransport $gateway): JsonResponse
    {
        return $this->gatewayResponse(fn () => $gateway->institutionInfo());
    }

    public function gatewayTestCheckApplication(Request $request, GatewayFisTransport $gateway): JsonResponse
    {
        return $this->gatewayResponse(fn () => $gateway->testCheckApplication($request->all()));
    }

    private function gatewayResponse(callable $callback): JsonResponse
    {
        try {
            return response()->json(['data' => $callback()]);
        } catch (FisIntegrationException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }
    }

    public function download(FisOutboundPackage $package): Response|JsonResponse
    {
        if (! $package->payload_path || ! Storage::disk('local')->exists($package->payload_path)) {
            return response()->json(['message' => 'Payload not found.'], Response::HTTP_NOT_FOUND);
        }
        return response(Storage::disk('local')->get($package->payload_path), 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="fis-outbound-package-'.$package->id.'.xml"']);
    }

    public function specInfo(FisSpecificationRegistry $registry): JsonResponse
    {
        $analysis = $registry->analysis();

        return response()->json([
            'enabled' => config('fis_api.enabled'),
            'mode' => config('fis_api.mode'),
            'schema_version' => $registry->schemaVersion(),
            'manifest' => $registry->manifest(),
            'xsd_loaded' => (bool) $registry->xsdPath(),
            'wsdl_loaded' => (bool) $registry->wsdlPath(),
            'disco_loaded' => (bool) $registry->discoPath(),
            'contract_status' => $analysis['status'] ?? 'missing',
            'operation_count' => count($analysis['operations'] ?? []),
            'production_send_allowed' => (bool) config('fis_api.allow_production_send'),
        ]);
    }
}
