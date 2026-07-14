<?php

namespace Tests\Unit;

use App\Services\FisIntegration\FisWsdlAnalyzer;
use PHPUnit\Framework\TestCase;

class FisWsdlAnalyzerTest extends TestCase
{
    public function test_it_extracts_binding_action_messages_and_faults_without_network_access(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wsdl-');
        file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<definitions xmlns="http://schemas.xmlsoap.org/wsdl/" xmlns:tns="urn:test" xmlns:soap="http://schemas.xmlsoap.org/wsdl/soap/" targetNamespace="urn:test">
  <message name="ReadRequest"/><message name="ReadResponse"/><message name="ReadFault"/>
  <portType name="ITest"><operation name="ReadStatus"><input message="tns:ReadRequest"/><output message="tns:ReadResponse"/><fault name="ServiceFault" message="tns:ReadFault"/></operation></portType>
  <binding name="BasicBinding" type="tns:ITest"><soap:binding transport="http://schemas.xmlsoap.org/soap/http" style="document"/><operation name="ReadStatus"><soap:operation soapAction="urn:test/ReadStatus"/></operation></binding>
  <service name="TestService"><port name="BasicPort" binding="tns:BasicBinding"><soap:address location="http://127.0.0.1/test"/></port></service>
</definitions>
XML);

        try {
            $analysis = (new FisWsdlAnalyzer())->analyze($path);
            self::assertSame('loaded', $analysis['status']);
            self::assertSame('urn:test', $analysis['target_namespace']);
            self::assertSame(['1.1'], $analysis['soap_versions']);
            self::assertSame('ReadStatus', $analysis['operations'][0]['name']);
            self::assertSame('urn:test/ReadStatus', $analysis['operations'][0]['bindings'][0]['soap_action']);
            self::assertSame('ServiceFault', $analysis['operations'][0]['faults'][0]['name']);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_extracts_xsd_roots_imports_and_payload_authentication_elements(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xsd-');
        file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:payload">
  <xs:import namespace="urn:dictionary" schemaLocation="dictionary.xsd"/>
  <xs:element name="Root">
    <xs:complexType><xs:sequence><xs:element name="AuthData"><xs:complexType><xs:sequence>
      <xs:element name="Login" type="xs:string"/>
      <xs:element name="Pass" type="xs:string"/>
      <xs:element name="InstitutionID" type="xs:string" minOccurs="0"/>
    </xs:sequence></xs:complexType></xs:element></xs:sequence></xs:complexType>
  </xs:element>
</xs:schema>
XML);

        try {
            $analysis = (new FisWsdlAnalyzer())->analyze(null, $path);
            self::assertSame('loaded', $analysis['xsd']['status']);
            self::assertSame('urn:payload', $analysis['xsd']['target_namespace']);
            self::assertSame(['Root'], $analysis['xsd']['root_elements']);
            self::assertSame('dictionary.xsd', $analysis['xsd']['imports'][0]['schema_location']);
            self::assertSame(['Login', 'Pass', 'InstitutionID'], $analysis['xsd']['authentication_elements']);
        } finally {
            @unlink($path);
        }
    }
}
