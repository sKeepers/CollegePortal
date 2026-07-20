<?php

namespace App\Services\FisIntegration;

use DOMDocument;
use DOMElement;
use DOMXPath;

class FisWsdlAnalyzer
{
    private const WSDL = 'http://schemas.xmlsoap.org/wsdl/';
    private const SOAP11 = 'http://schemas.xmlsoap.org/wsdl/soap/';
    private const SOAP12 = 'http://schemas.xmlsoap.org/wsdl/soap12/';

    public function analyze(?string $wsdlPath, ?string $xsdPath = null, ?string $discoPath = null): array
    {
        $result = [
            'status' => 'missing',
            'protocol' => 'xml_over_http',
            'http_method' => 'POST',
            'soap_contract' => 'not_applicable_official_support_confirmed_xml_over_http',
            'wsdl' => $this->fileInfo($wsdlPath),
            'xsd' => $this->analyzeXsd($xsdPath),
            'disco' => $this->analyzeDisco($discoPath),
            'target_namespace' => null,
            'soap_versions' => [],
            'bindings' => [],
            'services' => [],
            'operations' => [],
            'metadata_graph' => [
                'wsdl_imports' => [],
                'xsd_imports' => [],
                'xsd_includes' => [],
                'disco_contract_refs' => [],
                'disco_soap_refs' => [],
            ],
            'completeness' => [
                'has_operations' => false,
                'has_binding' => false,
                'has_service' => false,
                'has_port' => false,
                'has_soap_binding' => false,
                'has_soap_action' => false,
                'has_endpoint' => false,
                'complete' => false,
                'blockers' => [],
            ],
            'authentication' => 'unknown_until_official_contract_loaded',
            'errors' => [],
        ];

        if (! $wsdlPath || ! is_file($wsdlPath)) {
            $result['status'] = ($result['xsd']['status'] ?? 'missing') === 'loaded' ? 'xsd_loaded' : 'missing';
            return $result;
        }

        $document = $this->loadXml($wsdlPath, $result['errors']);
        if (! $document) {
            $result['status'] = 'invalid';
            return $result;
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('wsdl', self::WSDL);
        $xpath->registerNamespace('soap', self::SOAP11);
        $xpath->registerNamespace('soap12', self::SOAP12);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $definitions = $xpath->query('/wsdl:definitions')->item(0);
        $result['target_namespace'] = $definitions instanceof DOMElement ? $definitions->getAttribute('targetNamespace') : null;

        foreach ($xpath->query('//wsdl:import') as $import) {
            if ($import instanceof DOMElement) {
                $result['metadata_graph']['wsdl_imports'][] = [
                    'namespace' => $import->getAttribute('namespace') ?: null,
                    'location' => $import->getAttribute('location') ?: null,
                ];
            }
        }

        foreach ($xpath->query('//xs:import') as $import) {
            if ($import instanceof DOMElement) {
                $result['metadata_graph']['xsd_imports'][] = [
                    'namespace' => $import->getAttribute('namespace') ?: null,
                    'schema_location' => $import->getAttribute('schemaLocation') ?: null,
                ];
            }
        }

        foreach ($xpath->query('//xs:include') as $include) {
            if ($include instanceof DOMElement) {
                $result['metadata_graph']['xsd_includes'][] = [
                    'schema_location' => $include->getAttribute('schemaLocation') ?: null,
                ];
            }
        }

        $bindingActions = [];
        foreach ($xpath->query('//wsdl:binding') as $binding) {
            if (! $binding instanceof DOMElement) {
                continue;
            }
            $soapBinding = $xpath->query('./soap:binding | ./soap12:binding', $binding)->item(0);
            $soapVersion = $soapBinding?->namespaceURI === self::SOAP12 ? '1.2' : ($soapBinding ? '1.1' : null);
            $bindingName = $binding->getAttribute('name');
            $bindingInfo = [
                'name' => $bindingName,
                'port_type' => $binding->getAttribute('type'),
                'soap_version' => $soapVersion,
                'transport' => $soapBinding instanceof DOMElement ? $soapBinding->getAttribute('transport') : null,
                'style' => $soapBinding instanceof DOMElement ? $soapBinding->getAttribute('style') : null,
            ];
            $result['bindings'][] = $bindingInfo;
            if ($soapVersion) {
                $result['soap_versions'][] = $soapVersion;
            }

            foreach ($xpath->query('./wsdl:operation', $binding) as $operation) {
                if (! $operation instanceof DOMElement) {
                    continue;
                }
                $soapOperation = $xpath->query('./soap:operation | ./soap12:operation', $operation)->item(0);
                $bindingActions[$operation->getAttribute('name')][] = [
                    'binding' => $bindingName,
                    'soap_action' => $soapOperation instanceof DOMElement ? $soapOperation->getAttribute('soapAction') : null,
                    'style' => $soapOperation instanceof DOMElement ? $soapOperation->getAttribute('style') : null,
                    'headers' => $xpath->query('.//soap:header | .//soap12:header', $operation)->length,
                ];
            }
        }

        foreach ($xpath->query('//wsdl:portType/wsdl:operation') as $operation) {
            if (! $operation instanceof DOMElement) {
                continue;
            }
            $name = $operation->getAttribute('name');
            $faults = [];
            foreach ($xpath->query('./wsdl:fault', $operation) as $fault) {
                if ($fault instanceof DOMElement) {
                    $faults[] = ['name' => $fault->getAttribute('name'), 'message' => $fault->getAttribute('message')];
                }
            }
            $input = $xpath->query('./wsdl:input', $operation)->item(0);
            $output = $xpath->query('./wsdl:output', $operation)->item(0);
            $result['operations'][] = [
                'name' => $name,
                'input_message' => $input instanceof DOMElement ? $input->getAttribute('message') : null,
                'output_message' => $output instanceof DOMElement ? $output->getAttribute('message') : null,
                'faults' => $faults,
                'bindings' => $bindingActions[$name] ?? [],
            ];
        }

        foreach ($xpath->query('//wsdl:service') as $service) {
            if (! $service instanceof DOMElement) {
                continue;
            }
            $ports = [];
            foreach ($xpath->query('./wsdl:port', $service) as $port) {
                if (! $port instanceof DOMElement) {
                    continue;
                }
                $address = $xpath->query('./soap:address | ./soap12:address', $port)->item(0);
                $ports[] = [
                    'name' => $port->getAttribute('name'),
                    'binding' => $port->getAttribute('binding'),
                    'location' => $address instanceof DOMElement ? $address->getAttribute('location') : null,
                ];
            }
            $result['services'][] = ['name' => $service->getAttribute('name'), 'ports' => $ports];
        }

        $policyNodes = $xpath->query('//*[local-name()="Policy" or local-name()="Security" or local-name()="UsernameToken"]');
        $result['authentication'] = $policyNodes->length > 0
            ? 'policy_present_manual_verification_required'
            : 'not_declared_in_wsdl';
        $result['soap_versions'] = array_values(array_unique($result['soap_versions']));
        $result['status'] = $result['operations'] ? 'loaded' : 'invalid';
        if (! $result['operations']) {
            $result['errors'][] = 'WSDL contains no portType operations.';
        }
        $result['metadata_graph']['disco_contract_refs'] = $result['disco']['contract_refs'] ?? [];
        $result['metadata_graph']['disco_soap_refs'] = $result['disco']['soap_refs'] ?? [];
        $result['completeness'] = $this->completeness($result);

        return $result;
    }

    private function analyzeXsd(?string $path): array
    {
        $info = $this->fileInfo($path);
        if (! $path || ! is_file($path)) {
            return $info + ['target_namespace' => null, 'root_elements' => [], 'imports' => [], 'authentication_elements' => []];
        }

        $errors = [];
        $document = $this->loadXml($path, $errors);
        if (! $document) {
            return $info + ['status' => 'invalid', 'target_namespace' => null, 'root_elements' => [], 'imports' => [], 'authentication_elements' => [], 'errors' => $errors];
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $schema = $xpath->query('/xs:schema')->item(0);
        $roots = [];
        foreach ($xpath->query('/xs:schema/xs:element') as $element) {
            if ($element instanceof DOMElement) {
                $roots[] = $element->getAttribute('name');
            }
        }
        $imports = [];
        foreach ($xpath->query('/xs:schema/xs:import | /xs:schema/xs:include') as $import) {
            if ($import instanceof DOMElement) {
                $imports[] = ['namespace' => $import->getAttribute('namespace'), 'schema_location' => $import->getAttribute('schemaLocation')];
            }
        }

        $authenticationElements = [];
        foreach ($xpath->query('//xs:element[@name="AuthData"]//xs:element[@name]') as $element) {
            if ($element instanceof DOMElement) {
                $authenticationElements[] = $element->getAttribute('name');
            }
        }

        return $info + [
            'status' => 'loaded',
            'target_namespace' => $schema instanceof DOMElement ? ($schema->getAttribute('targetNamespace') ?: null) : null,
            'root_elements' => $roots,
            'imports' => $imports,
            'authentication_elements' => array_values(array_unique($authenticationElements)),
        ];
    }

    private function analyzeDisco(?string $path): array
    {
        $info = $this->fileInfo($path);
        if (! $path || ! is_file($path)) {
            return $info + ['contract_refs' => [], 'soap_refs' => [], 'errors' => []];
        }

        $errors = [];
        $document = $this->loadXml($path, $errors);
        if (! $document) {
            return $info + ['status' => 'invalid', 'contract_refs' => [], 'soap_refs' => [], 'errors' => $errors];
        }

        $xpath = new DOMXPath($document);
        $contractRefs = [];
        foreach ($xpath->query('//*[local-name()="contractRef"]') as $contractRef) {
            if ($contractRef instanceof DOMElement) {
                $contractRefs[] = [
                    'ref' => $contractRef->getAttribute('ref') ?: null,
                    'doc_ref' => $contractRef->getAttribute('docRef') ?: null,
                ];
            }
        }

        $soapRefs = [];
        foreach ($xpath->query('//*[local-name()="soap"]') as $soapRef) {
            if ($soapRef instanceof DOMElement) {
                $soapRefs[] = [
                    'address' => $soapRef->getAttribute('address') ?: null,
                    'binding' => $soapRef->getAttribute('binding') ?: null,
                ];
            }
        }

        return $info + [
            'status' => 'loaded',
            'contract_refs' => $contractRefs,
            'soap_refs' => $soapRefs,
            'errors' => [],
        ];
    }

    private function completeness(array $result): array
    {
        $services = $result['services'] ?? [];
        $operations = $result['operations'] ?? [];
        $bindings = $result['bindings'] ?? [];
        $ports = [];
        foreach ($services as $service) {
            foreach ($service['ports'] ?? [] as $port) {
                $ports[] = $port;
            }
        }
        $bindingActions = [];
        foreach ($operations as $operation) {
            foreach ($operation['bindings'] ?? [] as $binding) {
                $bindingActions[] = $binding;
            }
        }

        $state = [
            'has_operations' => $operations !== [],
            'has_binding' => $bindings !== [],
            'has_service' => $services !== [],
            'has_port' => $ports !== [],
            'has_soap_binding' => $this->containsFilled($bindings, 'soap_version'),
            'has_soap_action' => $this->containsFilled($bindingActions, 'soap_action'),
            'has_endpoint' => $this->containsFilled($ports, 'location'),
            'complete' => false,
            'blockers' => [],
        ];

        if (! $state['has_operations']) {
            $state['blockers'][] = 'soap_operations_missing';
        }
        if (! $state['has_binding']) {
            $state['blockers'][] = 'soap_binding_missing';
        }
        if (! $state['has_service']) {
            $state['blockers'][] = 'soap_service_missing';
        }
        if (! $state['has_port']) {
            $state['blockers'][] = 'soap_port_missing';
        }
        if (! $state['has_soap_binding']) {
            $state['blockers'][] = 'soap_version_missing';
        }
        if (! $state['has_soap_action']) {
            $state['blockers'][] = 'soap_actions_missing';
        }
        if (! $state['has_endpoint']) {
            $state['blockers'][] = 'soap_endpoint_missing';
        }

        $state['complete'] = $state['blockers'] === [];

        return $state;
    }

    private function containsFilled(array $items, string $key): bool
    {
        foreach ($items as $item) {
            if (isset($item[$key]) && $item[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    private function fileInfo(?string $path): array
    {
        return [
            'status' => $path && is_file($path) ? 'loaded' : 'missing',
            'name' => $path ? basename($path) : null,
            'sha256' => $path && is_file($path) ? hash_file('sha256', $path) : null,
        ];
    }

    private function loadXml(string $path, array &$errors): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->load($path, LIBXML_NONET | LIBXML_NOBLANKS);
        if (! $loaded) {
            foreach (libxml_get_errors() as $error) {
                $errors[] = trim($error->message).' at line '.$error->line;
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }
}