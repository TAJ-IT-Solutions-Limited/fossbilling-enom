<?php

declare(strict_types=1);

/**
 * One read-only Enom check against the live host.
 *
 * Credentials come from ENOM_UID and ENOM_TOKEN. They are not arguments, not printed, and not written to disk.
 * The only commands this process can send are GetBalance, Check, and GetDomainInfo.
 *
 * Usage, from this repository, on the billing server:
 *   ENOM_UID=... ENOM_TOKEN=... php tools/live_readonly_check.php /path/to/fossbilling name.tld existing.name.tld
 *   history -c
 */

const ENOM_LIVE_COMMANDS = ['GetBalance', 'Check', 'GetDomainInfo'];

final class EnomLiveRefused extends RuntimeException
{
}

final class EnomCurlResponse
{
    public function __construct(private string $body, private int $status, private array $headers)
    {
    }

    public function getContent(bool $throw = true): string
    {
        return $this->body;
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->headers;
    }
}

final class EnomCurlClient
{
    public function __construct(private int $timeout = 90)
    {
    }

    public function withOptions(array $options): self
    {
        $copy = clone $this;
        if (isset($options['timeout'])) {
            $copy->timeout = (int) $options['timeout'];
        }

        return $copy;
    }

    public function request(string $method, string $url, array $options = []): EnomCurlResponse
    {
        if ($method !== 'POST') {
            throw new RuntimeException('Enom live check sends POST only');
        }
        $fields = $options['body'] ?? [];
        if (!is_array($fields)) {
            throw new RuntimeException('Enom live check posts form fields');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('curl is not available');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('curl_init failed');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);
        if (!is_string($raw)) {
            throw new RuntimeException('curl exec failed');
        }
        $headerBlob = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $headers = ['content-type' => ['text/plain']];
        if (preg_match('/^content-type:\s*(.+)$/im', $headerBlob, $match) === 1) {
            $headers['content-type'] = [trim($match[1])];
        }

        return new EnomCurlResponse($body, $status, $headers);
    }
}

function enom_live_command_allowed(string $command): bool
{
    return in_array($command, ENOM_LIVE_COMMANDS, true);
}

function enom_live_dispatch(string $command, object $adapter, ?object $domain): mixed
{
    if (!enom_live_command_allowed($command)) {
        throw new EnomLiveRefused('command refused');
    }

    return match ($command) {
        'GetBalance' => $adapter->testApi(),
        'Check' => $adapter->isDomainAvailable($domain),
        'GetDomainInfo' => $adapter->getDomainDetails($domain),
        default => throw new EnomLiveRefused('command refused'),
    };
}

function enom_live_redact(string $text, string $uid, string $token): string
{
    if ($token !== '') {
        $text = str_replace($token, '[token]', $text);
    }
    if ($uid !== '') {
        $text = str_replace($uid, '[uid]', $text);
    }

    return preg_replace('/\b(pw|uid|password|token)=([^&\s]+)/i', '$1=[redacted]', $text) ?? $text;
}

/**
 * @param array{balance_ok: bool, check: string, expiry: string, ns_count: int, ns_hosts: string, registered_at_kept: bool, error: string} $row
 */
function enom_live_format(array $row): string
{
    $lines = [
        'balance_ok=' . ($row['balance_ok'] ? 'true' : 'false'),
        'check=' . $row['check'],
        'expiry=' . $row['expiry'],
        'ns=' . $row['ns_count'] . ($row['ns_hosts'] !== '' ? ' ' . $row['ns_hosts'] : ''),
        'registered_at_kept=' . ($row['registered_at_kept'] ? 'true' : 'false'),
    ];
    if ($row['error'] !== '') {
        $lines[] = 'error=' . $row['error'];
    }

    return implode("\n", $lines) . "\n";
}

function enom_live_split_domain(string $domain): array
{
    $domain = strtolower(trim($domain));
    $dot = strpos($domain, '.');
    if ($dot === false || $dot < 1 || $dot >= strlen($domain) - 1) {
        throw new EnomLiveRefused('domain must look like name.tld');
    }

    return [substr($domain, 0, $dot), substr($domain, $dot + 1)];
}

if (!class_exists('Registrar_Exception', false)) {
    class Registrar_Exception extends Exception
    {
    }
}

if (!class_exists('Registrar_AdapterAbstract', false)) {
    class Registrar_AdapterAbstract
    {
        public function getLog(): object
        {
            return new class {
                public function debug(string $message): void
                {
                }

                public function err(string $message): void
                {
                }

                public function info(string $message): void
                {
                }
            };
        }

        public function getHttpClient(): object
        {
            throw new RuntimeException('no http client');
        }
    }
}

if (!class_exists('Registrar_Domain', false)) {
    class Registrar_Domain
    {
        public ?int $expirationTime = null;
        public ?int $registrationTime = null;
        public array $nameservers = [];
        private string $sld = '';
        private string $tld = '';

        public function setSld(string $value): void
        {
            $this->sld = $value;
        }

        public function getSld(): string
        {
            return $this->sld;
        }

        public function setTld(string $value): void
        {
            $this->tld = $value;
        }

        public function getTld(bool $withDot = true): string
        {
            return $withDot ? '.' . $this->tld : $this->tld;
        }

        public function setExpirationTime(?int $ts): void
        {
            $this->expirationTime = $ts;
        }

        public function getExpirationTime(): ?int
        {
            return $this->expirationTime;
        }

        public function setRegistrationTime(?int $ts): void
        {
            $this->registrationTime = $ts;
        }

        public function getRegistrationTime(): ?int
        {
            return $this->registrationTime;
        }

        public function setNs1(string $value): void
        {
            $this->nameservers[1] = $value;
        }

        public function setNs2(string $value): void
        {
            $this->nameservers[2] = $value;
        }

        public function setNs3(string $value): void
        {
            $this->nameservers[3] = $value;
        }

        public function setNs4(string $value): void
        {
            $this->nameservers[4] = $value;
        }

        public function getNs1(): string
        {
            return $this->nameservers[1] ?? '';
        }

        public function getNs2(): string
        {
            return $this->nameservers[2] ?? '';
        }

        public function getNs3(): string
        {
            return $this->nameservers[3] ?? '';
        }

        public function getNs4(): string
        {
            return $this->nameservers[4] ?? '';
        }
    }
}

function enom_live_boot(string $fossPath): object
{
    $fossPath = rtrim($fossPath, "/\\");
    $autoload = $fossPath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
    if (!class_exists('Registrar_Adapter_Enom', false)) {
        require_once dirname(__DIR__) . '/src/library/Registrar/Adapter/Enom.php';
    }
    if (class_exists(\Symfony\Component\HttpClient\HttpClient::class)) {
        return \Symfony\Component\HttpClient\HttpClient::create();
    }

    return new EnomCurlClient();
}

function enom_live_domain(string $name): Registrar_Domain
{
    [$sld, $tld] = enom_live_split_domain($name);
    $domain = new Registrar_Domain();
    $domain->setSld($sld);
    $domain->setTld($tld);
    $domain->setRegistrationTime(1710000000);

    return $domain;
}

function enom_live_ns_hosts(object $domain): array
{
    $hosts = [];
    foreach (['getNs1', 'getNs2', 'getNs3', 'getNs4'] as $method) {
        if (!method_exists($domain, $method)) {
            continue;
        }
        $value = $domain->{$method}();
        if (is_string($value) && $value !== '') {
            $hosts[] = $value;
        }
    }

    return $hosts;
}

/**
 * @return array{balance_ok: bool, check: string, expiry: string, ns_count: int, ns_hosts: string, registered_at_kept: bool, error: string}
 */
function enom_live_collect(object $adapter, string $checkName, string $existingName, string $uid, string $token): array
{
    $error = '';
    $balanceOk = false;
    try {
        $balance = enom_live_dispatch('GetBalance', $adapter, null);
        $balanceOk = is_array($balance) && !empty($balance['ok']);
        if (!$balanceOk && is_array($balance) && isset($balance['error'])) {
            $error = (string) $balance['error'];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $check = 'error';
    try {
        $checkDomain = enom_live_domain($checkName);
        $available = enom_live_dispatch('Check', $adapter, $checkDomain);
        $check = $available ? 'available' : 'taken';
    } catch (Throwable $e) {
        $check = 'error';
        $error = $error !== '' ? $error : $e->getMessage();
    }

    $expiry = '';
    $hosts = [];
    $kept = false;
    try {
        $existing = enom_live_domain($existingName);
        enom_live_dispatch('GetDomainInfo', $adapter, $existing);
        $exp = $existing->getExpirationTime();
        if (is_int($exp) && $exp > 0) {
            $expiry = gmdate('Y-m-d', $exp);
        }
        $hosts = enom_live_ns_hosts($existing);
        $registered = $existing->getRegistrationTime();
        $kept = $registered !== null && $registered !== 0;
    } catch (Throwable $e) {
        $error = $error !== '' ? $error : $e->getMessage();
    }

    return [
        'balance_ok' => $balanceOk,
        'check' => $check,
        'expiry' => $expiry,
        'ns_count' => count($hosts),
        'ns_hosts' => implode(' ', $hosts),
        'registered_at_kept' => $kept,
        'error' => enom_live_redact($error, $uid, $token),
    ];
}

function enom_live_main(array $argv): void
{
    $foss = $argv[1] ?? '';
    $checkName = $argv[2] ?? '';
    $existingName = $argv[3] ?? '';
    if ($foss === '' || $checkName === '' || $existingName === '') {
        fwrite(STDERR, "usage: php tools/live_readonly_check.php <fossbilling-path> <check-domain> <existing-domain>\n");
        exit(2);
    }
    $uid = getenv('ENOM_UID');
    $token = getenv('ENOM_TOKEN');
    if (!is_string($uid) || trim($uid) === '' || !is_string($token) || trim($token) === '') {
        fwrite(STDERR, "ENOM_UID and ENOM_TOKEN must be set in the environment\n");
        exit(2);
    }
    $uid = trim($uid);
    $token = trim($token);

    try {
        $client = enom_live_boot($foss);
        $adapter = new Registrar_Adapter_Enom(['uid' => $uid, 'pw' => $token]);
        $adapter->setHttpClient($client);
        $row = enom_live_collect($adapter, $checkName, $existingName, $uid, $token);
    } catch (EnomLiveRefused $e) {
        fwrite(STDERR, enom_live_redact($e->getMessage(), $uid, $token) . "\n");
        exit(2);
    } catch (Throwable $e) {
        fwrite(STDERR, enom_live_redact($e->getMessage(), $uid, $token) . "\n");
        exit(1);
    }

    echo enom_live_format($row);
}

if (PHP_SAPI === 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    enom_live_main($argv);
}
