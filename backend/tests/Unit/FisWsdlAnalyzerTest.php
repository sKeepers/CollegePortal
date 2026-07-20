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
    public function test_it_extracts_disco_contract_refs_into_metadata_graph(): void
    {
        $wsdl = tempnam(sys_get_temp_dir(), 'wsdl-');
        $disco = tempnam(sys_get_temp_dir(), 'disco-');
        file_put_contents($wsdl, <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<definitions xmlns="http://schemas.xmlsoap.org/wsdl/" xmlns:tns="urn:test" targetNamespace="urn:test">
  <message name="ReadRequest"/><message name="ReadResponse"/>
  <portType name="ITest"><operation name="ReadStatus"><input message="tns:ReadRequest"/><output message="tns:ReadResponse"/></operation></portType>
</definitions>
XML);
        file_put_contents($disco, <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<discovery xmlns="http://schemas.xmlsoap.org/disco/">
  <contractRef ref="http://10.0.3.1:8383/api/import/ImportService.svc?wsdl" docRef="http://10.0.3.1:8383/api/import/ImportService.svc" xmlns="http://schemas.xmlsoap.org/disco/scl/" />
</discovery>
XML);

        try {
            $analysis = (new FisWsdlAnalyzer())->analyze($wsdl, null, $disco);
            self::assertSame('loaded', $analysis['disco']['status']);
            self::assertSame('http://10.0.3.1:8383/api/import/ImportService.svc?wsdl', $analysis['metadata_graph']['disco_contract_refs'][0]['ref']);
            self::assertContains('soap_binding_missing', $analysis['completeness']['blockers']);
        } finally {
            @unlink($wsdl);
            @unlink($disco);
        }
    }

    public function test_it_marks_porttype_only_wsdl_as_incomplete_metadata(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wsdl-');
        file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<definitions xmlns="http://schemas.xmlsoap.org/wsdl/" xmlns:tns="urn:test" targetNamespace="urn:test">
  <types><schema xmlns="http://www.w3.org/2001/XMLSchema" targetNamespace="urn:test"><import namespace="urn:payload" schemaLocation="http://10.0.3.1:8383/api/import/ImportService.svc?xsd=xsd0"/></schema></types>
  <message name="ReadRequest"/><message name="ReadResponse"/>
  <portType name="ITest"><operation name="GetTestDictionariesList"><input message="tns:ReadRequest"/><output message="tns:ReadResponse"/></operation></portType>
  <service name="ImportService" />
</definitions>
XML);

        try {
            $analysis = (new FisWsdlAnalyzer())->analyze($path);
            self::assertSame('loaded', $analysis['status']);
            self::assertSame('GetTestDictionariesList', $analysis['operations'][0]['name']);
            self::assertFalse($analysis['completeness']['complete']);
            self::assertContains('soap_binding_missing', $analysis['completeness']['blockers']);
            self::assertContains('soap_port_missing', $analysis['completeness']['blockers']);
            self::assertContains('soap_version_missing', $analysis['completeness']['blockers']);
            self::assertContains('soap_actions_missing', $analysis['completeness']['blockers']);
            self::assertSame('http://10.0.3.1:8383/api/import/ImportService.svc?xsd=xsd0', $analysis['metadata_graph']['xsd_imports'][0]['schema_location']);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_extracts_soap12_binding_version_and_action(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wsdl-');
        file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<definitions xmlns="http://schemas.xmlsoap.org/wsdl/" xmlns:tns="urn:test" xmlns:soap12="http://schemas.xmlsoap.org/wsdl/soap12/" targetNamespace="urn:test">
  <message name="ReadRequest"/><message name="ReadResponse"/>
  <portType name="ITest"><operation name="ReadStatus"><input message="tns:ReadRequest"/><output message="tns:ReadResponse"/></operation></portType>
  <binding name="Soap12Binding" type="tns:ITest"><soap12:binding transport="http://schemas.xmlsoap.org/soap/http" style="document"/><operation name="ReadStatus"><soap12:operation soapAction="urn:test/ReadStatus12"/></operation></binding>
  <service name="TestService"><port name="Soap12Port" binding="tns:Soap12Binding"><soap12:address location="http://127.0.0.1/test"/></port></service>
</definitions>
XML);

        try {
            $analysis = (new FisWsdlAnalyzer())->analyze($path);
            self::assertSame(['1.2'], $analysis['soap_versions']);
            self::assertSame('urn:test/ReadStatus12', $analysis['operations'][0]['bindings'][0]['soap_action']);
            self::assertTrue($analysis['completeness']['complete']);
        } finally {
            @unlink($path);
        }
    }
}
