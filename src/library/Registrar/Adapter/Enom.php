<?php

declare(strict_types=1);

/**
 * Enom (Tucows / OpenSRS) reseller registrar adapter — HTTP POST to interface.asp.
 *
 * Copy into your FOSSBilling install as:
 *   src/library/Registrar/Adapter/Enom.php
 * Class name must be {@see Registrar_Adapter_Enom} (file Enom.php).
 *
 * Enom’s default reply is key=value text (often preceded by `;URL Interface` comments),
 * not JSON. This adapter accepts text, XML, HTML, and JSON. Admin test: GetBalance.
 *
 * @see https://www.enom.com/API/docs/
 */
class Registrar_Adapter_Enom extends Registrar_AdapterAbstract
{
    public const ADAPTER_BUILD = '2026-11-07-enom-post-fail-closed';

    private const URL_PRODUCTION = 'https://reseller.enom.com/interface.asp';

    private const URL_TEST = 'https://resellertest.enom.com/interface.asp';

    /** @var array<string, mixed> */
    private array $config = [
        'uid' => '',
        'pw' => '',
        'sandbox' => false,
    ];

    /** @var object|null Test double. Production uses the parent HTTP client. */
    private ?object $httpClientOverride = null;

    /** @var array{http_status?: int, content_type?: string, parsed_as?: string, body_snippet?: string} */
    private array $lastHttpMeta = [];

    public function __construct($options)
    {
        if (isset($options['uid']) && is_string($options['uid']) && strlen(trim($options['uid'])) > 0) {
            $this->config['uid'] = trim($options['uid']);
        } else {
            throw new Registrar_Exception(
                'The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing',
                [':domain_registrar' => 'Enom', ':missing' => 'Enom UID (reseller login id)'],
                3001
            );
        }

        if (isset($options['pw']) && is_string($options['pw']) && strlen(trim($options['pw'])) > 0) {
            $this->config['pw'] = trim($options['pw']);
        } else {
            throw new Registrar_Exception(
                'The ":domain_registrar" domain registrar is not fully configured. Please configure the :missing',
                [':domain_registrar' => 'Enom', ':missing' => 'Enom PW (API token / password)'],
                3001
            );
        }

    }

    public function setHttpClient(object $client): void
    {
        $this->httpClientOverride = $client;
    }

    public function getHttpClient()
    {
        if ($this->httpClientOverride !== null) {
            return $this->httpClientOverride;
        }

        return parent::getHttpClient();
    }

    /**
     * FOSSBilling registrar “Enable Test Mode” must hit resellertest.enom.com.
     */
    public function enableTestMode()
    {
        $this->config['sandbox'] = true;

        return parent::enableTestMode();
    }

    /**
     * Registrar configuration for the FOSSBilling admin UI.
     *
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        return [
            'label' => 'Manages domains via the Enom reseller API. Use FOSSBilling test mode for the OTE host.',
            'form' => [
                'uid' => [
                    'text',
                    [
                        'label' => 'Enom UID',
                        'description' => 'Enom reseller account ID (login username)',
                        'required' => true,
                    ],
                ],
                'pw' => [
                    'password',
                    [
                        'label' => 'Enom PW',
                        'description' => 'Enom API password / token (never logged or returned in errors)',
                        'required' => true,
                    ],
                ],
            ],
        ];
    }

    /**
     * Whether the SLD is available for new registration on this TLD.
     */
    public function isDomainAvailable(Registrar_Domain $domain): bool
    {
        $sld = $domain->getSld();
        $tld = ltrim((string) $domain->getTld(false), '.');
        $data = $this->_makeRequest('Check', [
            'SLD' => $sld,
            'TLD' => $tld,
        ]);

        if (isset($data['RRPCode'])) {
            $code = (int) $data['RRPCode'];

            return $code === 210;
        }

        if (isset($data['Available'])) {
            return filter_var($data['Available'], FILTER_VALIDATE_BOOLEAN);
        }

        return false;
    }

    /**
     * Best-effort: if the name is not available for new registration, assume it may be eligible for transfer.
     */
    public function isDomaincanBeTransferred(Registrar_Domain $domain): bool
    {
        try {
            return !$this->isDomainAvailable($domain);
        } catch (Registrar_Exception) {
            return false;
        }
    }

    /**
     * Register the domain with Enom {@see https://www.enom.com/API/docs/Purchase.htm}.
     */
    public function registerDomain(Registrar_Domain $domain): bool
    {
        $c = $domain->getContactRegistrar();
        if (!$c) {
            throw new Registrar_Exception('Registrant contact is required for Enom domain registration');
        }

        $params = array_merge(
            [
                'SLD' => $domain->getSld(),
                'TLD' => ltrim((string) $domain->getTld(false), '.'),
                'NumYears' => $domain->getRegistrationPeriod() ?: 1,
                'EndUserIP' => $this->_endUserIp(),
            ],
            $this->_registrantParams($c)
        );

        if (method_exists($c, 'getAddress2') && $c->getAddress2()) {
            $params['RegistrantAddress2'] = $c->getAddress2();
        }
        if (method_exists($c, 'getCompany') && $c->getCompany()) {
            $params['RegistrantOrganizationName'] = $c->getCompany();
        }

        $ns = $this->_nameserverParams($domain);
        $params = array_merge($params, $ns);

        $this->_assertDone($this->_makeRequest('Purchase', $params), 'Purchase');

        return true;
    }

    /**
     * Start / submit a transfer order using the auth (EPP) code.
     */
    public function transferDomain(Registrar_Domain $domain): bool
    {
        $auth = $domain->getEpp();
        if ($auth === null || trim((string) $auth) === '') {
            throw new Registrar_Exception('Auth / EPP code is required for Enom domain transfer');
        }

        $params = [
            'OrderType' => 'Autoverification',
            'DomainCount' => '1',
            'SLD1' => $domain->getSld(),
            'TLD1' => ltrim((string) $domain->getTld(false), '.'),
            'AuthInfo1' => (string) $auth,
            'EndUserIP' => $this->_endUserIp(),
        ];
        $contact = $domain->getContactRegistrar();
        if ($contact) {
            $params = array_merge($params, $this->_presentRegistrantParams($contact));
        }
        $data = $this->_makeRequest('TP_CreateOrder', $params);
        $this->_assertDone($data, 'TP_CreateOrder');
        $orderId = $this->_firstKey($data, ['OrderID', 'TransferOrderID']);
        if (is_string($orderId) && $orderId !== '') {
            $this->getLog()->debug('Enom TP_CreateOrder id=' . $orderId);
        }

        return true;
    }

    /**
     * Renew the domain for {@see Registrar_Domain::getRegistrationPeriod()} years.
     */
    public function renewDomain(Registrar_Domain $domain): bool
    {
        $this->_assertDone($this->_makeRequest('Extend', [
            'SLD' => $domain->getSld(),
            'TLD' => ltrim((string) $domain->getTld(false), '.'),
            'NumYears' => $domain->getRegistrationPeriod() ?: 1,
            'EndUserIP' => $this->_endUserIp(),
        ]), 'Extend');

        return true;
    }

    /**
     * Populate expiry, nameservers, and basic status from Enom {@see https://www.enom.com/API/docs/GetDomainInfo.htm}.
     */
    public function getDomainDetails(Registrar_Domain $domain)
    {
        $data = $this->_makeRequest('GetDomainInfo', [
            'SLD' => $domain->getSld(),
            'TLD' => ltrim((string) $domain->getTld(false), '.'),
            'responsetype' => 'xml',
        ]);

        $exp = $this->_firstKey($data, ['ExpiryDate', 'expiration-date', 'ExpirationDate', 'expiredate']);
        if ($exp === null) {
            // Enom nests the date: GetDomainInfo > status > expiration (e.g. "10/17/2027 9:43:20 PM").
            $exp = $this->_deepKey($data, ['expiration', 'Expiration', 'ExpirationDate']);
        }
        if ($exp !== null && is_string($exp)) {
            $ts = strtotime($exp);
            if ($ts !== false) {
                $domain->setExpirationTime($ts);
            }
        }

        $created = $this->_firstKey($data, ['CreateDate', 'registration-date', 'RegistrationDate']);
        if (is_string($created) && trim($created) !== '') {
            $ts = strtotime($created);
            if ($ts !== false) {
                $domain->setRegistrationTime($ts);
            }
        }

        $this->_applyNameservers($domain, $data);

        $status = $this->_firstKey($data, ['registrationstatus', 'RegistrationStatus', 'ServiceStatus']);
        if ($status === null) {
            $status = $this->_deepKey($data, ['registrationstatus', 'RegistrationStatus']);
        }
        if ($status === null) {
            $status = $this->_firstKey($data, ['status', 'Status']);
        }
        if ($status !== null && is_string($status) && method_exists($domain, 'setStatus')) {
            $domain->setStatus($status);
        }

        return $domain;
    }

    /**
     * Update delegation name servers at the registry via Enom.
     */
    public function modifyNs(Registrar_Domain $domain): bool
    {
        $params = array_merge(
            [
                'SLD' => $domain->getSld(),
                'TLD' => ltrim((string) $domain->getTld(false), '.'),
                'EndUserIP' => $this->_endUserIp(),
            ],
            $this->_nameserverParams($domain)
        );

        $this->_assertDone($this->_makeRequest('ModifyNS', $params), 'ModifyNS');

        return true;
    }

    /**
     * Retrieve the transfer (auth) code from Enom.
     */
    public function getEpp(Registrar_Domain $domain): string
    {
        $data = $this->_makeRequest('GetAuthCode', [
            'SLD' => $domain->getSld(),
            'TLD' => ltrim((string) $domain->getTld(false), '.'),
            'EndUserIP' => $this->_endUserIp(),
        ]);

        $auth = $this->_firstKey($data, ['AuthCode', 'AuthInfo', 'authinfo', 'GetAuthCode']);
        if (is_string($auth) && $auth !== '') {
            return $auth;
        }

        throw new Registrar_Exception('Enom did not return an auth code for this domain');
    }

    /**
     * Reseller accounts cannot delete domains via this API surface.
     */
    public function deleteDomain(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception('Domain deletion is not supported by the Enom reseller API adapter');
    }

    public function modifyContact(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception('Contact updates via Enom API are not implemented in this adapter');
    }

    public function enablePrivacyProtection(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception('WHOIS privacy is not implemented in this Enom adapter');
    }

    public function disablePrivacyProtection(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception('WHOIS privacy is not implemented in this Enom adapter');
    }

    public function lock(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception('Registry lock via Enom API is not implemented in this adapter');
    }

    public function unlock(Registrar_Domain $domain): bool
    {
        throw new Registrar_Exception('Registry unlock via Enom API is not implemented in this adapter');
    }

    /**
     * FOSSBilling hosting-server convention: ping Enom with GetBalance (credentials + JSON/XML/text parse).
     * Does not register a domain.
     */
    public function testConnection(): bool
    {
        $result = $this->testApi();
        if (empty($result['ok'])) {
            throw new Registrar_Exception((string) ($result['error'] ?? 'Enom connection test failed'));
        }

        return true;
    }

    /**
     * Admin / diagnostic ping. Never logs UID/PW. Safe to call from FOSS admin API.
     *
     * @return array<string, mixed>
     */
    public function testApi(): array
    {
        $host = parse_url($this->_apiBaseUrl(), PHP_URL_HOST) ?: '';
        $out = [
            'ok' => false,
            'adapter_build' => self::ADAPTER_BUILD,
            'host' => is_string($host) ? $host : '',
            'sandbox' => $this->_isSandbox(),
            'command' => 'GetBalance',
        ];

        try {
            $data = $this->_makeRequest('GetBalance');
            $out['ok'] = true;
            $out['balance'] = $this->_firstKey($data, ['AvailableBalance', 'Balance']);
            $out['reseller'] = $this->_firstKey($data, ['Reseller']);
            $out['http_status'] = $this->lastHttpMeta['http_status'] ?? null;
            $out['content_type'] = $this->lastHttpMeta['content_type'] ?? null;
            $out['parsed_as'] = $this->lastHttpMeta['parsed_as'] ?? null;

            return $out;
        } catch (Registrar_Exception $e) {
            $out['error'] = $e->getMessage();
            $out['http_status'] = $this->lastHttpMeta['http_status'] ?? null;
            $out['content_type'] = $this->lastHttpMeta['content_type'] ?? null;
            $out['parsed_as'] = $this->lastHttpMeta['parsed_as'] ?? null;
            $out['body_snippet'] = $this->lastHttpMeta['body_snippet'] ?? null;

            return $out;
        }
    }

    /**
     * Execute a POST to Enom interface.asp. Credentials stay in the body.
     *
     * @param string $command Enom Command= value (e.g. Check, Purchase, GetBalance)
     * @param array<string, scalar|null> $params Additional query parameters (UID/PW/ResponseType added automatically)
     *
     * @return array<string, mixed> Parsed body (InterfaceResponse merged to top level where applicable)
     *
     * @throws Registrar_Exception
     */
    private function _makeRequest(string $command, array $params = []): array
    {
        $this->lastHttpMeta = [];
        $base = $this->_apiBaseUrl();

        $query = array_merge(
            [
                'command' => $command,
                'uid' => $this->config['uid'],
                'pw' => $this->config['pw'],
                'responsetype' => 'text',
            ],
            $params
        );

        $host = parse_url($base, PHP_URL_HOST);
        $this->getLog()->debug('Enom API [' . self::ADAPTER_BUILD . '] POST ' . $command . ' host=' . (is_string($host) ? $host : ''));

        $client = $this->getHttpClient()->withOptions(['timeout' => 90]);
        $body = '';

        try {
            $response = $client->request('POST', $base, ['body' => $query]);
            $body = $response->getContent(false);
            $this->lastHttpMeta['http_status'] = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $ct = $headers['content-type'][0] ?? $headers['Content-Type'][0] ?? '';
            $this->lastHttpMeta['content_type'] = is_string($ct) ? $ct : '';
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            $this->getLog()->err('Enom HTTP error: ' . $e->getMessage());
            throw new Registrar_Exception('Enom HTTP request failed');
        } catch (\Throwable $e) {
            $this->getLog()->err('Enom transport error: ' . $e->getMessage());
            throw new Registrar_Exception('Enom request failed');
        }

        $flat = $this->_parseEnomBody($body);

        if (!is_array($flat) || !array_key_exists('ErrCount', $flat)) {
            $this->getLog()->err('Enom unexpected response: ' . ($this->lastHttpMeta['body_snippet'] ?? $this->_bodySnippet($body)));
            throw new Registrar_Exception('unexpected response: check credentials / IP allow-list / WAF');
        }

        $errCount = (int) $flat['ErrCount'];
        if ($errCount > 0) {
            $msg = $this->_enomErrorMessage($flat);
            $this->getLog()->err('Enom API error: ' . $msg);
            throw new Registrar_Exception($msg);
        }

        /** @var array<string, mixed> $flat */
        return $flat;
    }

    private function _isSandbox(): bool
    {
        return !empty($this->config['sandbox']);
    }

    private function _apiBaseUrl(): string
    {
        return $this->_isSandbox() ? self::URL_TEST : self::URL_PRODUCTION;
    }

    /**
     * Enom text replies start with `;URL Interface` comments, then key=value lines.
     * XML/HTML may follow the same preamble. JSON is rare.
     *
     * @return array<string, mixed>
     */
    private function _parseEnomBody(string $body): array
    {
        $snippet = $this->_bodySnippet($body);
        $this->lastHttpMeta['body_snippet'] = $snippet;

        $normalized = $this->_normalizeEnomBody($body);

        $decoded = json_decode($normalized, true);
        if (is_array($decoded)) {
            $this->lastHttpMeta['parsed_as'] = 'json';

            return $this->_flattenEnom($decoded);
        }

        $trim = ltrim($normalized);
        $looksXml = $trim !== '' && ($trim[0] === '<' || strncasecmp($trim, '<?xml', 5) === 0)
            && !preg_match('/<(?:html|body|strong)\b/i', substr($trim, 0, 240));
        if ($looksXml && function_exists('simplexml_load_string')) {
            $xml = @simplexml_load_string($normalized, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($xml instanceof \SimpleXMLElement) {
                $asJson = json_decode(json_encode($xml), true);
                if (is_array($asJson)) {
                    $this->lastHttpMeta['parsed_as'] = 'xml';

                    return $this->_flattenEnom($asJson);
                }
            }
        }

        $html = $this->_parseEnomHtml($normalized);
        if ($html !== null) {
            $this->lastHttpMeta['parsed_as'] = 'html';

            return $html;
        }

        $text = $this->_parseEnomText($normalized);
        if ($text !== null) {
            $this->lastHttpMeta['parsed_as'] = 'text';

            return $text;
        }

        $status = $this->lastHttpMeta['http_status'] ?? 0;
        $ctype = $this->lastHttpMeta['content_type'] ?? '';
        $this->getLog()->err(
            'Enom unexpected response [' . self::ADAPTER_BUILD . '] HTTP ' . $status
            . ' content-type=' . $ctype . ' snippet=' . $snippet
        );

        throw new Registrar_Exception('unexpected response: check credentials / IP allow-list / WAF');
    }

    /**
     * Drop `;URL Interface` banner lines and treat HTML &lt;br&gt; as newlines.
     */
    private function _normalizeEnomBody(string $body): string
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $body = str_ireplace(['<br />', '<br/>', '<br>'], "\n", $body);

        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '' && $kept === []) {
                continue;
            }
            if ($trim !== '' && ($trim[0] === ';' || strncmp($trim, '&#59;', 5) === 0)) {
                continue;
            }
            $kept[] = $line;
        }

        return trim(implode("\n", $kept));
    }

    /**
     * @param array<string, mixed> $decoded
     *
     * @return array<string, mixed>
     */
    private function _flattenEnom(array $decoded): array
    {
        $ir = $decoded['InterfaceResponse'] ?? $decoded['interface-response'] ?? $decoded['interface_response'] ?? null;
        if (is_array($ir)) {
            // One level only. Nested blocks such as GetDomainInfo/services stay arrays.
            return array_merge($decoded, $ir);
        }

        $errors = $decoded['errors'] ?? $decoded['Errors'] ?? null;
        if (is_array($errors) && !isset($decoded['Err1'])) {
            $i = 1;
            foreach ($errors as $err) {
                if (is_string($err) && $err !== '') {
                    $decoded['Err' . $i] = $err;
                    ++$i;
                } elseif (is_array($err) && isset($err[0]) && is_string($err[0])) {
                    $decoded['Err' . $i] = $err[0];
                    ++$i;
                }
            }
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function _parseEnomText(string $body): ?array
    {
        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
        $out = [];
        $kv = 0;
        foreach ($lines as $line) {
            $line = trim(html_entity_decode($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($line === '' || str_starts_with($line, ';')) {
                continue;
            }
            $line = preg_replace('/^<STRONG>\s*/i', '', $line) ?? $line;
            $line = preg_replace('/<\/STRONG>/i', '', $line) ?? $line;
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
            } elseif (preg_match('/^([A-Za-z][A-Za-z0-9_]*)\s*:\s*(.*)$/', $line, $m)) {
                $k = $m[1];
                $v = $m[2];
            } else {
                continue;
            }
            $k = trim($k);
            if ($k === '' || str_starts_with($k, ';')) {
                continue;
            }
            $out[$k] = trim($v);
            ++$kv;
        }
        if ($kv >= 1 && (isset($out['ErrCount']) || isset($out['Command']) || isset($out['Done']) || isset($out['Balance']) || isset($out['AvailableBalance']))) {
            return $out;
        }
        if ($kv >= 3) {
            return $out;
        }

        return null;
    }

    /**
     * Enom ResponseType=HTML: `<STRONG>Key: </STRONG>Value`.
     *
     * @return array<string, mixed>|null
     */
    private function _parseEnomHtml(string $body): ?array
    {
        if (!preg_match('/<STRONG>/i', $body) && !preg_match('/<HTML>/i', $body)) {
            return null;
        }
        $out = [];
        if (preg_match_all('/<STRONG>\s*([^<:]+):\s*<\/STRONG>\s*([^<]*)/i', $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $k = trim($row[1]);
                if ($k === '') {
                    continue;
                }
                $out[$k] = trim(html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        if ($out === []) {
            return null;
        }
        if (isset($out['ErrCount']) || isset($out['Command']) || isset($out['Done']) || count($out) >= 3) {
            return $out;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $flat
     */
    private function _enomErrorMessage(array $flat): string
    {
        $msg = '';
        $nested = $flat['errors'] ?? $flat['Errors'] ?? null;
        if (is_array($nested)) {
            foreach ($nested as $err) {
                if (is_string($err) && $err !== '') {
                    $msg .= ($msg !== '' ? '; ' : '') . $err;
                } elseif (is_array($err)) {
                    $first = reset($err);
                    if (is_string($first) && $first !== '') {
                        $msg .= ($msg !== '' ? '; ' : '') . $first;
                    }
                }
            }
        }
        for ($i = 1; $i <= 9; ++$i) {
            $ek = 'Err' . $i;
            if (!empty($flat[$ek]) && is_string($flat[$ek])) {
                $msg .= ($msg !== '' ? '; ' : '') . $flat[$ek];
            }
        }
        if ($msg === '') {
            $msg = 'Enom API reported an error';
        }

        return $msg;
    }

    private function _bodySnippet(string $body): string
    {
        $len = strlen($body);
        $this->lastHttpMeta['body_bytes'] = $len;
        $one = str_replace(["\r\n", "\r", "\n"], ' | ', $body);
        $one = $this->_redactSecrets($one);
        if (strlen($one) > 500) {
            $one = substr($one, 0, 500) . '…';
        }

        return 'bytes=' . $len . ' ' . $one;
    }

    private function _redactSecrets(string $s): string
    {
        $s = preg_replace('/([?&](?:PW|pw|Password|password)=)[^&\s]*/i', '$1***', $s) ?? $s;

        return $s;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function _assertDone(array $data, string $action): void
    {
        if (array_key_exists('Done', $data)) {
            $done = $data['Done'];
            $ok = $done === true || $done === 1 || $done === '1' || (is_string($done) && strcasecmp($done, 'true') === 0);
            if (!$ok) {
                throw new Registrar_Exception($action . ' was not completed');
            }
        }
        if (isset($data['RRPCode']) && $data['RRPCode'] !== '') {
            $code = (int) $data['RRPCode'];
            if ($code !== 200 && $code !== 210) {
                throw new Registrar_Exception($action . ' was refused');
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function _registrantParams(Registrar_Domain_Contact $c): array
    {
        [$first, $last] = $this->_splitName((string) $c->getName());

        $params = [
            'RegistrantFirstName' => $this->_requiredRegistrant('FirstName', $first),
            'RegistrantLastName' => $this->_requiredRegistrant('LastName', $last),
            'RegistrantEmailAddress' => $this->_requiredRegistrant('EmailAddress', $c->getEmail()),
            'RegistrantAddress1' => $this->_requiredRegistrant('Address1', $c->getAddress1()),
            'RegistrantCity' => $this->_requiredRegistrant('City', $c->getCity()),
            'RegistrantCountry' => $this->_requiredRegistrant('Country', $c->getCountry()),
            'RegistrantPhone' => $this->_requiredRegistrant('Phone', $this->_formatPhone($c)),
        ];
        $this->_putIfPresent($params, 'RegistrantStateProvince', $c->getState());
        $this->_putIfPresent($params, 'RegistrantPostalCode', $c->getZip());

        return $params;
    }

    /**
     * Transfer orders send only the contact fields that exist. Empty state or postal code is omitted.
     *
     * @return array<string, string>
     */
    private function _presentRegistrantParams(Registrar_Domain_Contact $c): array
    {
        [$first, $last] = $this->_splitName((string) $c->getName());
        $params = [];
        $this->_putIfPresent($params, 'RegistrantFirstName', $first);
        $this->_putIfPresent($params, 'RegistrantLastName', $last);
        $this->_putIfPresent($params, 'RegistrantEmailAddress', $c->getEmail());
        $this->_putIfPresent($params, 'RegistrantAddress1', $c->getAddress1());
        $this->_putIfPresent($params, 'RegistrantCity', $c->getCity());
        $this->_putIfPresent($params, 'RegistrantStateProvince', $c->getState());
        $this->_putIfPresent($params, 'RegistrantPostalCode', $c->getZip());
        $this->_putIfPresent($params, 'RegistrantCountry', $c->getCountry());
        $this->_putIfPresent($params, 'RegistrantPhone', $this->_formatPhone($c));
        if (method_exists($c, 'getAddress2')) {
            $this->_putIfPresent($params, 'RegistrantAddress2', $c->getAddress2());
        }
        if (method_exists($c, 'getCompany')) {
            $this->_putIfPresent($params, 'RegistrantOrganizationName', $c->getCompany());
        }

        return $params;
    }

    /**
     * @param array<string, string> $params
     */
    private function _putIfPresent(array &$params, string $key, ?string $value): void
    {
        $value = trim((string) $value);
        if ($value !== '') {
            $params[$key] = $value;
        }
    }

    private function _requiredRegistrant(string $field, ?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            throw new Registrar_Exception('Registrant ' . $field . ' is required');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function _applyNameservers(Registrar_Domain $domain, array $data): void
    {
        $hosts = $this->_nameserversFromDomainInfo($data);
        if ($hosts === []) {
            for ($i = 1; $i <= 4; ++$i) {
                $value = $data['NS' . $i] ?? null;
                if (is_string($value) && $value !== '') {
                    $hosts[] = $value;
                }
            }
        }
        foreach ($hosts as $index => $host) {
            $slot = $index + 1;
            if ($slot > 4) {
                break;
            }
            $setter = 'setNs' . $slot;
            if (method_exists($domain, $setter)) {
                $domain->{$setter}($host);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    private function _nameserversFromDomainInfo(array $data): array
    {
        $blocks = [];
        if (isset($data['services']) && is_array($data['services'])) {
            $blocks[] = $data['services'];
        }
        foreach (['GetDomainInfo', 'getdomaininfo'] as $key) {
            if (isset($data[$key]['services']) && is_array($data[$key]['services'])) {
                $blocks[] = $data[$key]['services'];
            }
        }
        $hosts = [];
        foreach ($blocks as $services) {
            $hosts = array_merge($hosts, $this->_hostsFromServiceEntries($services));
        }

        return $hosts;
    }

    /**
     * @param array<string, mixed> $services
     *
     * @return list<string>
     */
    private function _hostsFromServiceEntries(array $services): array
    {
        $entries = $services['entry'] ?? null;
        if (!is_array($entries)) {
            return [];
        }
        if (isset($entries['@attributes']) || isset($entries['configuration']) || isset($entries['name'])) {
            $entries = [$entries];
        }
        $hosts = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $entry['name'] ?? ($entry['@attributes']['name'] ?? '');
            if ($name !== 'dnsserver') {
                continue;
            }
            $dns = $entry['configuration']['dns'] ?? null;
            if (is_string($dns) && $dns !== '') {
                $hosts[] = $dns;
            } elseif (is_array($dns)) {
                foreach ($dns as $item) {
                    if (is_string($item) && $item !== '') {
                        $hosts[] = $item;
                    }
                }
            }
        }

        return $hosts;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function _splitName(string $full): array
    {
        $full = trim($full);
        if ($full === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s+/', $full, 2);

        return [
            $parts[0],
            $parts[1] ?? $parts[0],
        ];
    }

    private function _formatPhone(Registrar_Domain_Contact $c): string
    {
        $cc = trim((string) $c->getTelCc());
        $tel = preg_replace('/[^0-9+]/', '', (string) $c->getTel()) ?? '';
        if ($cc !== '' && $tel !== '') {
            return '+' . ltrim($cc, '+') . '.' . ltrim($tel, '+');
        }

        return $tel;
    }

    /** @return array<string, string> NS1..NS4 for Enom */
    private function _nameserverParams(Registrar_Domain $domain): array
    {
        $out = [];
        $list = array_filter(
            [
                $domain->getNs1(),
                $domain->getNs2(),
                $domain->getNs3(),
                $domain->getNs4(),
            ],
            static fn ($v) => is_string($v) && $v !== ''
        );
        $list = array_values($list);
        for ($i = 0; $i < 4; ++$i) {
            $out['NS' . ($i + 1)] = $list[$i] ?? '';
        }

        return $out;
    }

    private function _endUserIp(): string
    {
        if (!empty($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }

        return '127.0.0.1';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function _deepKey(array $data, array $keys): ?string
    {
        $found = $this->_firstKey($data, $keys);
        if ($found !== null) {
            return $found;
        }
        foreach ($data as $v) {
            if (is_array($v)) {
                $found = $this->_deepKey($v, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function _firstKey(array $data, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (isset($data[$k]) && (is_string($data[$k]) || is_numeric($data[$k]))) {
                return (string) $data[$k];
            }
        }

        return null;
    }

    private function _redactUrl(string $url): string
    {
        $parts = @parse_url($url);
        if (!is_array($parts) || empty($parts['query'])) {
            return $url;
        }
        parse_str($parts['query'], $q);
        if (isset($q['PW'])) {
            $q['PW'] = '***';
        }
        if (isset($q['pw'])) {
            $q['pw'] = '***';
        }

        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $path = $parts['path'] ?? '';

        return $scheme . '://' . $host . $path . '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
    }
}
