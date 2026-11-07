<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require __DIR__ . '/FakeEnomClient.php';

final class EnomRequestTest extends TestCase
{
    private function adapter(FakeEnomClient $client): Registrar_Adapter_Enom
    {
        $adapter = new Registrar_Adapter_Enom(['uid' => 'fixture-user', 'pw' => 'fixture-pass']);
        $adapter->setHttpClient($client);

        return $adapter;
    }

    private function body(array $fields): FakeEnomClient
    {
        $lines = [];
        foreach ($fields as $key => $value) {
            $lines[] = $key . '=' . $value;
        }

        return new FakeEnomClient([implode("\n", $lines) . "\n"]);
    }

    public function testPurchaseExtendTransferAndModifyArePostedWithoutThePasswordInTheUrl(): void
    {
        $ok = ['ErrCount' => '0', 'Done' => 'true', 'RRPCode' => '200', 'OrderID' => '55'];
        $cases = [
            'registerDomain' => 'Purchase',
            'renewDomain' => 'Extend',
            'transferDomain' => 'TP_CreateOrder',
            'modifyNs' => 'ModifyNS',
        ];
        foreach ($cases as $method => $command) {
            $client = $this->body($ok);
            $domain = enom_test_domain();
            $this->assertTrue($this->adapter($client)->{$method}($domain));
            $this->assertSame('POST', $client->calls[0][0]);
            $this->assertSame('https://reseller.enom.com/interface.asp', $client->calls[0][1]);
            $this->assertArrayNotHasKey('query', $client->calls[0][2]);
            $body = $client->calls[0][2]['body'];
            $this->assertSame($command, $body['command']);
            $this->assertSame('fixture-user', $body['uid']);
            $this->assertSame('fixture-pass', $body['pw']);
            $this->assertStringNotContainsString('fixture-pass', $client->calls[0][1]);
        }
    }

    public function testTransferUsesIndexedEnomFields(): void
    {
        $client = $this->body(['ErrCount' => '0', 'Done' => 'true', 'RRPCode' => '200', 'TransferOrderID' => '77']);
        $this->adapter($client)->transferDomain(enom_test_domain());
        $body = $client->calls[0][2]['body'];
        $this->assertSame('Autoverification', $body['OrderType']);
        $this->assertSame('1', $body['DomainCount']);
        $this->assertSame('example', $body['SLD1']);
        $this->assertSame('com', $body['TLD1']);
        $this->assertSame('epp-code', $body['AuthInfo1']);
        $this->assertArrayNotHasKey('SLD', $body);
        $this->assertArrayNotHasKey('AuthInfo', $body);
        $this->assertSame('Ada Lovelace', $body['RegistrantFirstName'] . ' ' . $body['RegistrantLastName']);
    }

    public function testMissingErrCountIsRejected(): void
    {
        $client = new FakeEnomClient(["<html><title>Attention Required</title><body>blocked</body></html>\n"]);
        $this->expectException(Registrar_Exception::class);
        $this->expectExceptionMessage('unexpected response: check credentials / IP allow-list / WAF');
        $this->adapter($client)->isDomainAvailable(enom_test_domain());
    }

    public function testDoneFalseIsRejected(): void
    {
        $client = $this->body(['ErrCount' => '0', 'Done' => 'false', 'RRPCode' => '200']);
        $this->expectException(Registrar_Exception::class);
        $this->expectExceptionMessage('Purchase was not completed');
        $this->adapter($client)->registerDomain(enom_test_domain());
    }

    public function testUnexpectedRrpCodeIsRejected(): void
    {
        $client = $this->body(['ErrCount' => '0', 'Done' => 'true', 'RRPCode' => '540']);
        $this->expectException(Registrar_Exception::class);
        $this->expectExceptionMessage('Extend was refused');
        $this->adapter($client)->renewDomain(enom_test_domain());
    }

    public function testRegistrationRefusesAMissingCountry(): void
    {
        $contact = enom_test_contact(['country' => '']);
        $client = $this->body(['ErrCount' => '0', 'Done' => 'true', 'RRPCode' => '200']);
        $this->expectException(Registrar_Exception::class);
        $this->expectExceptionMessage('Registrant Country is required');
        $this->adapter($client)->registerDomain(enom_test_domain($contact));
    }

    public function testNameserversComeFromTheDocumentedDnsEntries(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<interface-response>
  <ErrCount>0</ErrCount>
  <Done>true</Done>
  <services>
    <entry name="dnsserver">
      <configuration>
        <dns>ns1.example.net</dns>
        <dns>ns2.example.net</dns>
      </configuration>
    </entry>
  </services>
</interface-response>
XML;
        $client = new FakeEnomClient([$xml]);
        $domain = enom_test_domain();
        $domain->setRegistrationTime(1710000000);
        $this->adapter($client)->getDomainDetails($domain);
        $this->assertSame('xml', $client->calls[0][2]['body']['responsetype']);
        $this->assertSame('ns1.example.net', $domain->getNs1());
        $this->assertSame('ns2.example.net', $domain->getNs2());
        $this->assertSame(1710000000, $domain->getRegistrationTime());
    }

    public function testUaeRegistrationFillsStateFromTheCityAndUsesThePostalPlaceholder(): void
    {
        $contact = enom_test_contact(['state' => '', 'zip' => '']);
        $client = $this->body(['ErrCount' => '0', 'Done' => 'true', 'RRPCode' => '200']);
        $this->assertTrue($this->adapter($client)->registerDomain(enom_test_domain($contact)));
        $body = $client->calls[0][2]['body'];
        $this->assertSame('Abu Dhabi', $body['RegistrantCity']);
        $this->assertSame('Abu Dhabi', $body['RegistrantStateProvince']);
        $this->assertSame('AE', $body['RegistrantCountry']);
        $this->assertSame(Registrar_Adapter_Enom::POSTAL_CODE_NONE, $body['RegistrantPostalCode']);
    }

    public function testRegistrationRefusesAMissingPostalCodeOutsideTheNoCodeList(): void
    {
        $contact = enom_test_contact(['country' => 'US', 'state' => 'WA', 'zip' => '']);
        $client = $this->body(['ErrCount' => '0', 'Done' => 'true', 'RRPCode' => '200']);
        $this->expectException(Registrar_Exception::class);
        $this->expectExceptionMessage('Registrant PostalCode is required');
        $this->adapter($client)->registerDomain(enom_test_domain($contact));
    }

    public function testQueuedPurchaseCodeIsSuccess(): void
    {
        $client = $this->body(['ErrCount' => '0', 'Done' => 'true', 'RRPCode' => '1300', 'OrderID' => '42']);
        $this->assertTrue($this->adapter($client)->registerDomain(enom_test_domain()));
    }

    public function testRegistrationRefusesAMissingCity(): void
    {
        $contact = enom_test_contact(['city' => '']);
        $client = $this->body(['ErrCount' => '0', 'Done' => 'true', 'RRPCode' => '200']);
        $this->expectException(Registrar_Exception::class);
        $this->expectExceptionMessage('Registrant City is required');
        $this->adapter($client)->registerDomain(enom_test_domain($contact));
    }

    public function testTransferOmitsMissingContactFields(): void
    {
        $contact = enom_test_contact(['city' => '', 'state' => '', 'zip' => '']);
        $client = $this->body(['ErrCount' => '0', 'OrderID' => '88']);
        $this->assertTrue($this->adapter($client)->transferDomain(enom_test_domain($contact)));
        $body = $client->calls[0][2]['body'];
        $this->assertSame('epp-code', $body['AuthInfo1']);
        $this->assertArrayNotHasKey('RegistrantFirstName', $body);
        $this->assertArrayNotHasKey('RegistrantCity', $body);
        $this->assertArrayNotHasKey('RegistrantStateProvince', $body);
        $this->assertArrayNotHasKey('RegistrantPostalCode', $body);
        $this->assertArrayNotHasKey('Done', $body);
        $this->assertArrayNotHasKey('RRPCode', $body);
    }

    public function testNestedGetDomainInfoKeepsServicesAndReadsStatus(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<interface-response>
  <GetDomainInfo>
    <status>
      <registrationstatus>Registered</registrationstatus>
      <expiration>10/17/2027 9:43:20 PM</expiration>
    </status>
    <services>
      <entry name="dnsserver">
        <configuration>
          <dns>ns1.nested.example</dns>
          <dns>ns2.nested.example</dns>
        </configuration>
      </entry>
    </services>
  </GetDomainInfo>
  <ErrCount>0</ErrCount>
</interface-response>
XML;
        $client = new FakeEnomClient([$xml]);
        $domain = new EnomStatusDomain();
        $domain->setSld('example');
        $domain->setTld('com');
        $this->adapter($client)->getDomainDetails($domain);
        $this->assertSame('ns1.nested.example', $domain->getNs1());
        $this->assertSame('ns2.nested.example', $domain->getNs2());
        $this->assertSame('Registered', $domain->status);
        $this->assertSame('2027-10-17', gmdate('Y-m-d', (int) $domain->getExpirationTime()));
    }

    public function testTransferPendingConsentIsSuccess(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<interface-response>
 <transferorder>
  <transferorderid>445413</transferorderid>
  <statusid>4</statusid>
  <statusdesc>Processing</statusdesc>
  <transferorderdetail>
   <statusid>100</statusid>
   <statusdesc>Pending consent</statusdesc>
  </transferorderdetail>
 </transferorder>
 <ErrCount>0</ErrCount>
 <Done>true</Done>
</interface-response>
XML;
        $client = new FakeEnomClient([$xml]);
        $this->assertTrue($this->adapter($client)->transferDomain(enom_test_domain()));
    }

    public function testEnomDatesKeepTheCalendarDayInTheBillingTimezone(): void
    {
        $previous = date_default_timezone_get();
        try {
            foreach (['UTC', 'Asia/Dubai', 'America/New_York'] as $zone) {
                date_default_timezone_set($zone);
                foreach (['11/6/2027', '11/6/2027 7:34:00 PM'] as $raw) {
                    $client = $this->body(['ErrCount' => '0', 'expiration' => $raw]);
                    $domain = enom_test_domain();
                    $this->adapter($client)->getDomainDetails($domain);
                    $stamp = (int) $domain->getExpirationTime();
                    $this->assertSame('2027-11-06', gmdate('Y-m-d', $stamp), $zone . ' ' . $raw);
                    $this->assertSame('2027-11-06', date('Y-m-d', $stamp), $zone . ' ' . $raw);
                }
            }
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testAvailabilityAcceptsAnIndexedRrpCode(): void
    {
        $client = $this->body(['ErrCount' => '0', 'DomainName1' => 'free.example', 'RRPCode1' => '210']);
        $this->assertTrue($this->adapter($client)->isDomainAvailable(enom_test_domain()));
    }

    public function testAvailabilityAcceptsAnIndexedTakenCode(): void
    {
        $client = $this->body(['ErrCount' => '0', 'DomainName1' => 'taken.example', 'RRPCode1' => '211']);
        $this->assertFalse($this->adapter($client)->isDomainAvailable(enom_test_domain()));
    }

    public function testPurchaseWithoutNameserversUsesEnomDefaultDns(): void
    {
        $client = $this->body(['ErrCount' => '0', 'Done' => 'true', 'RRPCode' => '200']);
        $domain = enom_test_domain();
        $domain->setNs1('');
        $domain->setNs2('');
        $domain->setNs3('');
        $domain->setNs4('');
        $this->assertTrue($this->adapter($client)->registerDomain($domain));
        $body = $client->calls[0][2]['body'];
        $this->assertSame('default', $body['UseDNS']);
        $this->assertArrayNotHasKey('NS1', $body);
    }

    public function testPurchaseWithNameserversDoesNotSendUseDns(): void
    {
        $client = $this->body(['ErrCount' => '0', 'Done' => 'true', 'RRPCode' => '200']);
        $domain = enom_test_domain();
        $domain->setNs1('ns1.example.net');
        $domain->setNs2('ns2.example.net');
        $this->assertTrue($this->adapter($client)->registerDomain($domain));
        $body = $client->calls[0][2]['body'];
        $this->assertSame('ns1.example.net', $body['NS1']);
        $this->assertArrayNotHasKey('UseDNS', $body);
    }

    public function testNameserverRequestOmitsEmptySlots(): void
    {
        $client = $this->body(['ErrCount' => '0', 'Done' => 'true', 'RRPCode' => '200']);
        $domain = enom_test_domain();
        $domain->setNs1('ns1.example.net');
        $domain->setNs2('ns2.example.net');
        $this->assertTrue($this->adapter($client)->modifyNs($domain));
        $body = $client->calls[0][2]['body'];
        $this->assertSame('ns1.example.net', $body['NS1']);
        $this->assertSame('ns2.example.net', $body['NS2']);
        $this->assertArrayNotHasKey('NS3', $body);
        $this->assertArrayNotHasKey('NS4', $body);
    }

    public function testModifyNsRefusesAnEmptyNameserverList(): void
    {
        $client = $this->body(['ErrCount' => '0', 'Done' => 'true']);
        $domain = enom_test_domain();
        $domain->setNs1('');
        $domain->setNs2('');
        try {
            $this->adapter($client)->modifyNs($domain);
            $this->fail('modifyNs with no nameservers must throw');
        } catch (Registrar_Exception $e) {
            $this->assertStringContainsString('At least one nameserver is required', $e->getMessage());
        }
        $this->assertSame([], $client->calls, 'no request may reach Enom');
    }

    public function testTransferUppercasesTheCountryCode(): void
    {
        $client = $this->body(['ErrCount' => '0', 'Done' => 'true', 'RRPCode' => '200', 'TransferOrderID' => '91']);
        $this->adapter($client)->transferDomain(enom_test_domain(enom_test_contact(['country' => 'ae'])));
        $this->assertSame('AE', $client->calls[0][2]['body']['RegistrantCountry']);
    }

    public function testGetEppReturnsTheAuthCode(): void
    {
        $client = $this->body(['ErrCount' => '0', 'AuthCode' => '  ABC123  ']);
        $this->assertSame('ABC123', $this->adapter($client)->getEpp(enom_test_domain()));
    }

    public function testGetEppExplainsTheManualAlternativesWhenEnomRefuses(): void
    {
        $client = $this->body(['ErrCount' => '1', 'Err1' => 'Command not found']);
        try {
            $this->adapter($client)->getEpp(enom_test_domain());
            $this->fail('getEpp must throw when Enom refuses GetAuthCode');
        } catch (Registrar_Exception $e) {
            $this->assertStringContainsString('control panel', $e->getMessage());
            $this->assertStringContainsString('SynchAuthInfo', $e->getMessage());
            $this->assertSame(3004, $e->getCode());
        }
    }

    public function testGetEppExplainsTheManualAlternativesWhenNoCodeIsReturned(): void
    {
        $client = $this->body(['ErrCount' => '0', 'Done' => 'true']);
        $this->expectException(Registrar_Exception::class);
        $this->expectExceptionMessage('returned no auth code');
        $this->adapter($client)->getEpp(enom_test_domain());
    }
}

final class EnomStatusDomain extends Registrar_Domain
{
    public ?string $status = null;

    public function setStatus($status): void
    {
        $this->status = is_string($status) ? $status : null;
    }
}
