<?php

namespace Paymenter\Extensions\Servers\Enom\Api;

final class RegistrarClient
{
    public const REQUEST_TIMEOUT_SECONDS = 25;

    private const ENDPOINTS = [
        'test' => 'https://resellertest.enom.com/interface.asp',
        'live' => 'https://reseller.enom.com/interface.asp',
    ];
    private const BODY_LIMIT = 1048576;
    /** Catalogue reads list every ending Enom sells and need more room than per-domain commands. */
    private const CATALOGUE_COMMANDS = ['gettldlist'];
    private const CATALOGUE_BODY_LIMIT = 8388608;
    private const COMMANDS = [
        'check', 'gettlddetails', 'getextattributes', 'getbalance', 'getdomaininfo',
        'getdomainexp', 'getextendinfo', 'purchase', 'extend',
        'getorderlist', 'getorderdetail',
        'tp_createorder',
        'tp_getorder', 'tp_getorderdetail',
        'getcontacts', 'contacts', 'getdns', 'modifyns', 'getreglock',
        'setreglock', 'synchauthinfo', 'getwppsinfo', 'purchaseservices', 'renewservices',
        'enableservices', 'disableservices', 'getrenew', 'setrenew', 'gethosts', 'sethosts',
        'getdomainsrvhosts', 'setdomainsrvhosts', 'getdnssec', 'adddnssec', 'deletednssec',
        'getexpireddomains', 'updateexpireddomains', 'gettldlist', 'pe_getproductprice',
        'checknsstatus', 'registernameserver', 'updatenameserver', 'deletenameserver',
    ];
    private const WRITES = [
        'purchase', 'extend', 'tp_createorder',
        'contacts', 'modifyns', 'setreglock', 'synchauthinfo', 'purchaseservices',
        'renewservices', 'enableservices', 'disableservices', 'setrenew', 'sethosts',
        'setdomainsrvhosts', 'adddnssec', 'deletednssec', 'updateexpireddomains',
        'registernameserver', 'updatenameserver', 'deletenameserver',
    ];
    private const READS = [
        'check', 'gettlddetails', 'getextattributes', 'getbalance', 'getdomaininfo',
        'getdomainexp', 'getextendinfo', 'getorderlist', 'getorderdetail',
        'tp_getorder',
        'tp_getorderdetail',
        'getcontacts', 'getdns', 'getreglock', 'getwppsinfo', 'getrenew', 'gethosts',
        'getdomainsrvhosts', 'getdnssec', 'getexpireddomains', 'gettldlist',
        'pe_getproductprice', 'checknsstatus',
    ];

    public function __construct(private string $uid, private string $token, private $transport = null, private $guard = null, private bool $readOnly = false, private string $environment = 'test')
    {
        if ($transport !== null && !is_callable($transport)) {
            throw new \InvalidArgumentException('Invalid registrar transport.');
        }
        if ($guard !== null && !is_callable($guard)) {
            throw new \InvalidArgumentException('Invalid registrar guard.');
        }
        if (!isset(self::ENDPOINTS[$environment])) {
            throw new \InvalidArgumentException('Invalid registrar environment.');
        }
    }

    public function isReadOnly(): bool { return $this->readOnly; }
    public function environment(): string { return $this->environment; }
    public function __debugInfo(): array { return ['environment' => $this->environment, 'injected_transport' => $this->transport !== null, 'read_only' => $this->readOnly]; }

    public function execute(string $command, array $parameters = []): RegistrarResult
    {
        $normalized = strtolower($command);
        if (!in_array($normalized, self::COMMANDS, true)) {
            throw new \InvalidArgumentException('Unsupported registrar command.');
        }
        if ($this->readOnly && !in_array($normalized, self::READS, true)) {
            return new RegistrarResult('rejected', 'read_only');
        }
        if ($normalized === 'updateexpireddomains') {
            $keys = array_map('strtolower', array_keys($parameters));
            $request = array_combine($keys, array_values($parameters));
            if (count($keys) !== count(array_unique($keys)) || array_diff($keys, ['domainname', 'numyears', 'usecreditcard'])
                || ($request['numyears'] ?? null) !== 1 || ($request['usecreditcard'] ?? null) !== 'no') {
                throw new \InvalidArgumentException('Expired renewal requires one safe one-year domain request.');
            }
            $name = (string) ($request['domainname'] ?? '');
            $parts = DomainName::parse($name, [substr($name, (int) strpos($name, '.') + 1)]);
            if ($parts['domain'] !== ($request['domainname'] ?? null) || str_starts_with($parts['sld'], 'xn--')) {
                throw new \InvalidArgumentException('Invalid existing-domain renewal request.');
            }
        }
        $seen = [];
        foreach ($parameters as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]*$/iD', $key)
                || in_array(strtolower($key), ['uid', 'pw', 'command', 'responsetype'], true)
                || (!is_string($value) && !is_int($value) && !is_bool($value))) {
                throw new \InvalidArgumentException('Invalid registrar request parameter.');
            }
            $lower = strtolower($key);
            if (isset($seen[$lower])) { throw new \InvalidArgumentException('Duplicate registrar request parameter.'); }
            $seen[$lower] = true;
            if (in_array(strtolower($key), ['overrideorder', 'usewiretransfer', 'deleteorderdetail'], true)
                || (strtolower($key) === 'usecreditcard' && strtolower((string) $value) !== 'no')) {
                throw new \InvalidArgumentException('This registrar payment option is unavailable.');
            }
            if ((in_array($lower, ['renewname', 'renewflag', 'renew', 'wppsrenew', 'autopakrenew', 'emailforwardrenew', 'urlforwardrenew', 'emailnotify'], true)
                && !in_array(strtolower((string) $value), ['0', 'false', 'no', ''], true))
                || in_array($lower, ['creditcardnumber', 'creditcardexpmonth', 'creditcardexpyear', 'cvv2', 'ccname', 'chargeamount', 'premiumdomain', 'customersuppliedprice'], true)) {
                throw new \InvalidArgumentException('This registrar billing or notification option is unavailable.');
            }
        }
        if (isset($seen['emailepp']) && $normalized !== 'synchauthinfo') {
            throw new \InvalidArgumentException('This registrar notification option is unavailable.');
        }
        if ($this->guard !== null) {
            try { ($this->guard)($command, $this->readOnly); }
            catch (\Throwable) { return new RegistrarResult('rejected', 'guard_refused'); }
        }
        try {
            $body = $this->transport !== null
                ? ($this->transport)($command, $parameters)
                : $this->send($command, $parameters);
            if (!is_string($body)) {
                return new RegistrarResult('unknown', 'transport');
            }
            return $this->parse($normalized, $body);
        } catch (\Throwable) {
            // Exceptions/raw cURL errors can contain credentials. Never propagate them.
            return new RegistrarResult('unknown', 'transport');
        }
    }

    private function send(string $command, array $parameters): string
    {
        if ($this->uid === '' || $this->token === '' || (getenv('SSLKEYLOGFILE') !== false && getenv('SSLKEYLOGFILE') !== '')) {
            throw new \RuntimeException('Registrar runtime guard refused the request.');
        }
        $curl = curl_init(self::ENDPOINTS[$this->environment]);
        $body = '';
        $limit = self::bodyLimit(strtolower($command));
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['Command' => $command, 'UID' => $this->uid, 'PW' => $this->token, 'ResponseType' => 'XML'] + $parameters, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/xml'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_VERBOSE => false,
            CURLOPT_HEADER => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
            CURLOPT_LOW_SPEED_LIMIT => 32,
            CURLOPT_LOW_SPEED_TIME => 10,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        try {
            $ok = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($ok === false || $status !== 200) {
                throw new \RuntimeException('Registrar transport did not return a complete response.');
            }
            return $body;
        } finally {
            curl_close($curl);
        }
    }

    private function parse(string $command, string $body): RegistrarResult
    {
        if (strlen($body) > self::bodyLimit($command) || preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) {
            return new RegistrarResult('unknown', 'invalid_response');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($body, LIBXML_NONET | LIBXML_NOBLANKS)
                || !$document->documentElement || strtolower($document->documentElement->tagName) !== 'interface-response') {
                return new RegistrarResult('unknown', 'invalid_response');
            }
            $count = 0;
            $this->nodeLimit = in_array($command, self::CATALOGUE_COMMANDS, true) ? 400000 : 20000;
            $data = $this->node($document->documentElement, 0, $count);
        } catch (\Throwable) {
            return new RegistrarResult('unknown', 'invalid_response');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!is_array($data) || !isset($data['command'], $data['errcount'], $data['done'])
            || !is_string($data['command']) || strtolower($data['command']) !== $command
            || !is_string($data['errcount']) || !ctype_digit($data['errcount'])
            || !is_string($data['done']) || strtolower($data['done']) !== 'true') {
            return new RegistrarResult('unknown', 'invalid_envelope');
        }
        if ((int) $data['errcount'] > 0) {
            $code = $this->safeCode($data['rrpcode'] ?? $data['responsecode'] ?? null);
            // A glue change the registry refused reports NsSuccess other than 1 alongside its errors; nothing was created or changed.
            $hostRefused = in_array($command, ['registernameserver', 'updatenameserver', 'deletenameserver'], true)
                && (string) ($data['nssuccess'] ?? $data['registernameserver']['nssuccess'] ?? '') !== '1';
            $status = !in_array($command, self::WRITES, true) || $hostRefused || $this->definitiveRefusal($data) ? 'rejected' : 'unknown';
            return new RegistrarResult($status, $code, $data);
        }
        if (isset($data['errors']) && $data['errors'] !== '') {
            return new RegistrarResult('unknown', 'contradictory_errors', $data);
        }
        foreach ($data as $key => $value) {
            if (preg_match('/^err\d+$/', $key) && $value !== '') {
                return new RegistrarResult('unknown', 'contradictory_errors', $data);
            }
        }
        $result = new RegistrarResult('success', $this->safeCode($data['rrpcode'] ?? $data['responsecode'] ?? null), $data);
        if ($command === 'check') {
            $domain = $result->value('domains.domain', $data);
            if (is_array($domain) && array_is_list($domain)) {
                $domain = count($domain) === 1 ? $domain[0] : null;
            }
            $code = is_array($domain) ? ($domain['rrpcode'] ?? null) : null;
            return new RegistrarResult(in_array($code, ['210', '211'], true) ? 'success' : 'unknown', $this->safeCode($code), $data);
        }
        if ($command === 'purchase') {
            if (($data['rrpcode'] ?? null) === '1300' || in_array(strtolower((string) ($data['orderstatus'] ?? '')), ['pending', 'processing', 'queued'], true)) {
                return new RegistrarResult('pending', $result->code, $data);
            }
            return new RegistrarResult(($data['rrpcode'] ?? null) === '200' && $this->id($data['orderid'] ?? null) && $result->confirmedExpiry() ? 'success' : 'unknown', $result->code, $data);
        }
        if ($command === 'extend') {
            return new RegistrarResult(($data['rrpcode'] ?? null) === '200' && strtolower((string) ($data['extension'] ?? '')) === 'successful' && $this->id($data['orderid'] ?? null) && $result->confirmedExpiry() ? 'success' : 'unknown', $result->code, $data);
        }
        if ($command === 'updateexpireddomains') {
            $reactivation = $data['reactivatedomainname'] ?? null;
            return new RegistrarResult(is_array($reactivation) && !array_is_list($reactivation)
                && strtolower((string) ($reactivation['status'] ?? '')) === 'true'
                && $this->id($reactivation['orderid'] ?? null) ? 'pending' : 'unknown', $result->code, $data);
        }
        if ($command === 'tp_createorder') {
            return new RegistrarResult($this->id($data['transferorderid'] ?? null) ? 'pending' : 'unknown', $result->code, $data);
        }
        if ($command === 'getdomainexp' && !$result->confirmedExpiry()) {
            return new RegistrarResult('unknown', 'invalid_expiry', $data);
        }
        if (in_array($command, ['purchaseservices', 'renewservices'], true) && !$this->id($data['orderid'] ?? null)) {
            return new RegistrarResult('unknown', 'missing_order', $data);
        }
        // Glue changes report NsSuccess=1 when the registry accepted them.
        if (in_array($command, ['registernameserver', 'updatenameserver', 'deletenameserver'], true)) {
            $flag = $data['nssuccess'] ?? ($data['registernameserver']['nssuccess'] ?? null);
            if ((string) $flag !== '1') { return new RegistrarResult('unknown', 'host_change_unconfirmed', $data); }
        }
        // EmailEPP=True answers with EPPEmailMessage instead of InfoSynched.
        if ($command === 'synchauthinfo' && strtolower((string) ($data['infosynched'] ?? '')) !== 'true'
            && !(is_string($data['eppemailmessage'] ?? null) && trim($data['eppemailmessage']) !== '')) {
            return new RegistrarResult('unknown', 'sync_unconfirmed', $data);
        }
        $validPayload = match ($command) {
            'getreglock' => $this->flag($data['reg-lock'] ?? null),
            'getrenew' => $this->flag($data['auto-renew'] ?? $data['renewname'] ?? null)
                && (!array_key_exists('auto-renew', $data) || !array_key_exists('renewname', $data)
                    || ($this->flag($data['renewname']) && filter_var($data['auto-renew'], FILTER_VALIDATE_BOOLEAN) === filter_var($data['renewname'], FILTER_VALIDATE_BOOLEAN))),
            'getwppsinfo' => is_array($data['getwppsinfo'] ?? null)
                && $this->flag($data['getwppsinfo']['wppsallowed'] ?? null)
                && $this->flag($data['getwppsinfo']['wppsexists'] ?? null)
                && $this->flag($data['getwppsinfo']['wppsenabled'] ?? null),
            'getcontacts' => is_array($data['getcontacts'] ?? null) && is_array($data['getcontacts']['registrant'] ?? null),
            'getdomaininfo' => is_array($data['getdomaininfo'] ?? null) && is_array($data['getdomaininfo']['status'] ?? null)
                && isset($data['getdomaininfo']['domainname']),
            'getdns' => isset($data['dns']) && $this->strings($data['dns']),
            'gethosts' => isset($data['host']) ? $this->records($data['host'], ['name', 'type', 'address'])
                : isset($data['domainservices']) && is_array($data['domainservices']) && in_array(strtolower((string) ($data['domainservices']['hostrecords'] ?? '')), ['true', '1'], true),
            'getdomainsrvhosts' => array_key_exists('srv-records', $data)
                && ($data['srv-records'] === '' || (is_array($data['srv-records']) && $this->records($data['srv-records']['srv'] ?? null, ['hostname', 'protocol', 'address', 'priority', 'weight', 'port']))),
            default => true,
        };
        if (!$validPayload) { return new RegistrarResult('unknown', 'invalid_payload', $data); }
        if (isset($data['responsecode']) && (!is_string($data['responsecode']) || !preg_match('/^1[0-9]{5}$/D', $data['responsecode']))) {
            // Accept only the documented six-digit success codes. Other codes stay unknown.
            return new RegistrarResult('unknown', $result->code, $data);
        }
        if ($command === 'tp_getorderdetail') {
            $detail = $data['transferorderdetail'] ?? $data;
            $status = is_array($detail) ? ($detail['statusid'] ?? null) : null;
            $outcome = $status === '5' ? 'success' : (in_array($status, ['0', '1', '3', '9', '10', '11', '12', '13', '14', '28', '29', '35', '100'], true) ? 'pending' : (in_array($status, ['2', '4', '6', '7', '8', '15', '16', '17', '18', '19', '20', '21', '22', '23', '24', '25', '26', '27', '30', '31', '32', '33', '34', '36', '37', '45', '101'], true) ? 'rejected' : 'unknown'));
            return new RegistrarResult($outcome, $this->safeCode($status), $data);
        }
        if ($command === 'tp_getorder') {
            $header = $data['transferorder'] ?? [];
            $status = is_array($header) ? ($header['statusid'] ?? null) : null;
            return new RegistrarResult($status === '3' ? 'success' : ($status === '2' ? 'rejected' : (in_array($status, ['0', '1', '4', '5'], true) ? 'pending' : 'unknown')), $this->safeCode($status), $data);
        }
        if ($command === 'getorderdetail') {
            $order = $data['order'] ?? [];
            if (!is_array($order) || strtolower((string) ($order['result'] ?? '')) !== 'true') {
                return new RegistrarResult('unknown', 'order_not_confirmed', $data);
            }
            $details = $order['orderdetail'] ?? [];
            $details = is_array($details) && array_is_list($details) ? $details : [$details];
            $states = [];
            foreach ($details as $detail) {
                $states[] = !is_array($detail) ? 'unknown' : (strtolower((string) ($detail['orderstatus'] ?? '')) === 'open' ? 'pending' : (strtolower((string) ($detail['orderstatus'] ?? '')) === 'closed' ? (strtolower((string) ($detail['status'] ?? '')) === 'successful' ? 'success' : 'rejected') : 'unknown'));
            }
            $status = !$states || in_array('unknown', $states, true) ? 'unknown' : (in_array('pending', $states, true) ? 'pending' : (in_array('rejected', $states, true) ? 'rejected' : 'success'));
            return new RegistrarResult($status, $result->code, $data);
        }
        // Read and nonfinancial-write successes are acknowledgements. Callers verify their postconditions.
        if (isset($data['rrpcode']) && !in_array($data['rrpcode'], ['200', '210', '211'], true)) {
            return new RegistrarResult('unknown', $result->code, $data);
        }
        return $result;
    }

    private function id(mixed $value): bool
    {
        return is_string($value) && ctype_digit($value) && trim($value, '0') !== '';
    }

    /** Documented refusals only. Server/timeout/duplicate/unknown errors remain uncertain. */
    private function definitiveRefusal(array $data): bool
    {
        // A provider order/expiry alongside an error may reflect partial execution.
        foreach (['orderid', 'transferorderid', 'registryexpdate'] as $field) {
            if (isset($data[$field]) && $data[$field] !== '') { return false; }
        }
        // Purchase also returns registry expiry inside DomainInfo. Even malformed or
        // repeated evidence can mean partial execution and must prevent a safe refusal.
        $domainInfo = $data['domaininfo'] ?? null;
        if ($domainInfo !== null && $domainInfo !== '') {
            if (!is_array($domainInfo) || array_is_list($domainInfo)) { return false; }
            if (isset($domainInfo['registryexpdate']) && $domainInfo['registryexpdate'] !== '') { return false; }
        }
        $reactivation = $data['reactivatedomainname'] ?? null;
        if ($reactivation !== null && $reactivation !== '') {
            if (!is_array($reactivation) || array_is_list($reactivation)
                || (isset($reactivation['orderid']) && $reactivation['orderid'] !== '')) { return false; }
        }
        $codes = [];
        $blank = true;
        foreach (['rrpcode', 'responsecode'] as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== '') { $blank = false; }
        }
        // Enom checks request fields before contacting the registry and answers those refusals
        // with no code at all. Only recognised field-validation messages count as definite.
        if ($blank) { return $this->validationRefusal($data); }
        foreach (['rrpcode', 'responsecode'] as $field) {
            if (array_key_exists($field, $data)) {
                $code = $this->safeCode($data[$field]);
                if ($code === null) { return false; }
                $codes[] = $code;
            }
        }
        if (!$codes) { return false; }
        foreach ($codes as $code) {
            // RRP: invalid/missing inputs, auth/permission, financial standing,
            // renewal window and explicit registry policy/status refusals.
            if (in_array($code, ['500', '501', '502', '503', '504', '505', '506', '507', '508', '509', '530', '531', '535', '541', '542', '543', '544', '545', '546', '547', '548', '550', '551', '552', '556'], true)) { continue; }
            // API code components: validation/auth/policy plus explicit refusal bits.
            // Failure-to-process/deduct and all registry/system/unknown codes are excluded.
            if (preg_match('/^[347](?:01|03|04|05|06|12|13|15|16|17)[0-9]{3}$/D', $code)) { continue; }
            if ($code === '524181') { continue; } // Insufficient reseller account balance.
            return false;
        }
        return true;
    }

    private function validationRefusal(array $data): bool
    {
        $messages = [];
        $source = is_array($data['errors'] ?? null) ? $data['errors'] + $data : $data;
        foreach ($source as $key => $value) {
            if (preg_match('/^err[0-9]+$/D', (string) $key) && is_string($value) && trim($value) !== '') { $messages[] = trim($value); }
        }
        if (!$messages || count($messages) !== (int) ($data['errcount'] ?? -1)) { return false; }
        foreach ($messages as $message) {
            if (!preg_match('/^(?:Invalid [A-Za-z ]+ information\.?|Parameter value policy error;.*|.*\bis (?:not a valid|required|invalid)\b.*|Invalid (?:phone|email|country|postal code|state|nameserver)\b.*)$/i', $message)) { return false; }
        }
        return true;
    }

    private function flag(mixed $value): bool
    {
        return is_string($value) && in_array(strtolower($value), ['1', '0', 'true', 'false', 'yes', 'no'], true);
    }

    private function strings(mixed $value): bool
    {
        $values = is_array($value) && array_is_list($value) ? $value : [$value];
        foreach ($values as $item) { if (!is_string($item) || $item === '') { return false; } }
        return true;
    }

    private function records(mixed $value, array $fields): bool
    {
        if (!is_array($value) || !$value) { return false; }
        $values = array_is_list($value) ? $value : [$value];
        foreach ($values as $record) {
            if (!is_array($record)) { return false; }
            foreach ($fields as $field) { if (!isset($record[$field]) || !is_string($record[$field])) { return false; } }
        }
        return true;
    }

    private function safeCode(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{1,6}$/D', $value) ? $value : null;
    }

    private int $nodeLimit = 20000;

    private static function bodyLimit(string $command): int
    {
        return in_array($command, self::CATALOGUE_COMMANDS, true) ? self::CATALOGUE_BODY_LIMIT : self::BODY_LIMIT;
    }

    private function node(\DOMElement $node, int $depth, int &$count): array|string
    {
        if ($depth > 16 || ++$count > $this->nodeLimit) {
            throw new \RuntimeException('Registrar response exceeds limits.');
        }
        $data = [];
        foreach ($node->attributes as $attribute) {
            $data['@attributes'][strtolower($attribute->name)] = $attribute->value;
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $key = strtolower($child->tagName);
                $value = $this->node($child, $depth + 1, $count);
                if (array_key_exists($key, $data)) {
                    if (!is_array($data[$key]) || !array_is_list($data[$key])) {
                        $data[$key] = [$data[$key]];
                    }
                    $data[$key][] = $value;
                } else {
                    $data[$key] = $value;
                }
            } elseif ($child instanceof \DOMText || $child instanceof \DOMCdataSection) {
                $text .= $child->textContent;
            }
        }
        $preserveText = $this->isTxtAddress($node);
        if (!$data) {
            return $preserveText ? $text : trim($text);
        }
        if ($preserveText ? $text !== '' : trim($text) !== '') {
            $data['#text'] = $preserveText ? $text : trim($text);
        }
        return $data;
    }

    /** Only a GetHosts TXT address carries verbatim DNS text; envelopes retain normal trimming. */
    private function isTxtAddress(\DOMElement $node): bool
    {
        $host = $node->parentNode;
        $root = $node->ownerDocument->documentElement;
        if (strtolower($node->tagName) !== 'address' || !$host instanceof \DOMElement
            || strtolower($host->tagName) !== 'host' || $host->parentNode !== $root) { return false; }
        $commands = $types = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'command') {
                $commands[] = strtolower(trim($child->textContent));
            }
        }
        foreach ($host->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'type') {
                $types[] = strtoupper(trim($child->textContent));
            }
        }

        return $commands === ['gethosts'] && $types === ['TXT'];
    }
}
