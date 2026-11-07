<?php

declare(strict_types=1);

/**
 * Fresh process. Constants must come from the harness, not from this file.
 * Loads the vendored FOSSBilling 0.8.9 registrar classes and runs the read-only
 * harness against a fake HTTP client.
 */

foreach (['DEBUG', 'PATH_ROOT', 'PATH_CONFIG', 'BIND_TO'] as $name) {
    if (defined($name)) {
        fwrite(STDERR, $name . " was defined before the harness\n");
        exit(1);
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

foreach (['DEBUG', 'PATH_ROOT', 'PATH_CONFIG', 'BIND_TO'] as $name) {
    if (defined($name)) {
        fwrite(STDERR, $name . " was defined by composer autoload\n");
        exit(1);
    }
}

require dirname(__DIR__) . '/tools/live_readonly_check.php';

if (!defined('DEBUG') || DEBUG !== false) {
    fwrite(STDERR, "harness did not set DEBUG=false\n");
    exit(1);
}
if (!defined('PATH_CONFIG')) {
    fwrite(STDERR, "harness did not set PATH_CONFIG\n");
    exit(1);
}
$emptyConfig = include PATH_CONFIG;
if ($emptyConfig !== []) {
    fwrite(STDERR, "PATH_CONFIG is not an empty array\n");
    exit(1);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'enom-harness-start-' . (string) getmypid();
$vendorDir = $root . DIRECTORY_SEPARATOR . 'vendor';
if (!is_dir($vendorDir) && !mkdir($vendorDir, 0777, true) && !is_dir($vendorDir)) {
    fwrite(STDERR, "could not create the fake FOSSBilling tree\n");
    exit(1);
}

$core = dirname(__DIR__) . '/tests/fossbilling-0.8.9';
$pieces = [
    $core . '/support-stubs.php',
    $core . '/Exception.php',
    $core . '/Contact.php',
    $core . '/Domain.php',
    $core . '/AdapterAbstract.php',
];
$autoload = "<?php\ndeclare(strict_types=1);\n";
foreach ($pieces as $piece) {
    $autoload .= 'require ' . var_export($piece, true) . ";\n";
}
$autoload .= <<<'PHP'
if (DEBUG !== false) {
    throw new RuntimeException('DEBUG was not false when the vendored classes loaded');
}
$config = include PATH_CONFIG;
if ($config !== []) {
    throw new RuntimeException('vendored load included a non-empty config');
}
PHP;
file_put_contents($vendorDir . DIRECTORY_SEPARATOR . 'autoload.php', $autoload);

$client = enom_live_boot($root);
if (!defined('PATH_ROOT') || PATH_ROOT !== $root) {
    fwrite(STDERR, "PATH_ROOT was not the FOSSBilling path\n");
    exit(1);
}
if (!$client instanceof EnomCurlClient && !$client instanceof Symfony\Contracts\HttpClient\HttpClientInterface) {
    fwrite(STDERR, "boot did not return an HTTP client\n");
    exit(1);
}

require dirname(__DIR__) . '/tests/FakeEnomClient.php';

$balance = "ErrCount=0\nDone=true\nAvailableBalance=1\n";
$check = "ErrCount=0\nRRPCode=210\n";
$info = <<<'XML'
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

$adapter = new Registrar_Adapter_Enom(['uid' => 'fixture-user', 'pw' => 'fixture-pass']);
$adapter->setHttpClient(new FakeEnomClient([$balance, $check, $info]));
$row = enom_live_collect($adapter, 'example.com', 'existing.com', 'fixture-user', 'fixture-pass');
if ($row['balance_ok'] !== true || $row['check'] !== 'available' || $row['error'] !== '') {
    fwrite(STDERR, 'harness did not start: ' . json_encode($row) . "\n");
    exit(1);
}

$failure = new Error('pw=fixture-pass uid=fixture-user');
$printed = enom_live_error_text($failure, 'fixture-user', 'fixture-pass');
$where = $failure::class . ' ' . $failure->getFile() . ':' . $failure->getLine();
if (!str_contains($printed, $where) || str_contains($printed, 'fixture-pass') || str_contains($printed, 'fixture-user')) {
    fwrite(STDERR, "error line was not usable: {$printed}\n");
    exit(1);
}

echo "harness_started=yes\n";
