<?php

declare(strict_types=1);

/**
 * Recorded Enom responses. No network calls.
 */

class Registrar_Exception extends Exception
{
}

class Registrar_AdapterAbstract
{
    public function enableTestMode()
    {
        return true;
    }
}

require dirname(__DIR__) . '/src/library/Registrar/Adapter/Enom.php';

function ha_call(Registrar_Adapter_Enom $adapter, string $method, array $args)
{
    $ref = new ReflectionMethod($adapter, $method);
    $ref->setAccessible(true);

    return $ref->invokeArgs($adapter, $args);
}

function ha_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$adapter = new Registrar_Adapter_Enom(['uid' => 'fixture-user', 'pw' => 'fixture-pass']);

$text = <<<'TXT'
;URL Interface
;Machine is fixture
ErrCount=0
Command=CHECK
RRPCode=210
TXT;
$parsed = ha_call($adapter, '_parseEnomBody', [$text]);
if (($parsed['RRPCode'] ?? '') !== '210' || ($parsed['ErrCount'] ?? '') !== '0') {
    ha_fail('text response was not parsed');
}

$xml = <<<'XML'
<?xml version="1.0"?>
<interface-response>
  <ErrCount>0</ErrCount>
  <Command>GETBALANCE</Command>
  <Balance>10.00</Balance>
</interface-response>
XML;
$parsed = ha_call($adapter, '_parseEnomBody', [$xml]);
if (($parsed['Balance'] ?? '') !== '10.00') {
    ha_fail('xml response was not parsed');
}

$json = '{"ErrCount":0,"Command":"CHECK","Available":"yes"}';
$parsed = ha_call($adapter, '_parseEnomBody', [$json]);
if (($parsed['Available'] ?? '') !== 'yes') {
    ha_fail('json response was not parsed');
}

$open = "ErrCount=1\nErr1=This domain has an open order\n";
$parsed = ha_call($adapter, '_parseEnomBody', [$open]);
$message = ha_call($adapter, '_enomErrorMessage', [$parsed]);
if (!str_contains($message, 'open order')) {
    ha_fail('open order error was not kept');
}

$details = [
    'GetDomainInfo' => [
        'status' => [
            'expiration' => '10/17/2027 9:43:20 PM',
        ],
    ],
];
$expiry = ha_call($adapter, '_deepKey', [$details, ['expiration', 'Expiration', 'ExpirationDate']]);
if ($expiry !== '10/17/2027 9:43:20 PM') {
    ha_fail('nested expiry was not read');
}

$adapter->enableTestMode();
if (ha_call($adapter, '_apiBaseUrl', []) !== 'https://resellertest.enom.com/interface.asp') {
    ha_fail('test mode did not select the reseller test host');
}

$redacted = ha_call($adapter, '_redactSecrets', ['https://reseller.enom.com/interface.asp?pw=fixture-pass&uid=fixture-user']);
if (str_contains($redacted, 'fixture-pass')) {
    ha_fail('password remained in the redacted url');
}

echo "enom_parse_ok\n";
