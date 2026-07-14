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
            'wsdl' => $this->fileInfo($wsdlPath),
            'xsd' => $this->analyzeXsd($xsdPath),
            'disco' => $this->fileInfo($discoPath),
            'target_namespace' => null,
            'soap_versions' => [],
            'bindings' => [],
            'services' => [],
            'operations' => [],
            'authentication' => 'unknown_until_official_contract_loaded',
            'errors' => [],
        ];

        if (! $wsdlPath || ! is_file($wsdlPath)) {
            $result['errors'][] = 'Official FIS WSDL is not loaded.';
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
        $definitions = $xpath->query('/wsdl:definitions')->item(0);
        $result['target_namespace'] = $definitions instanceof DOMElement ? $definitions->getAttribute('targetNamespace') : null;

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
