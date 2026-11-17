<?php
// Official PHP client for the Matheel ZATCA Phase 2 e-invoicing API (https://matheel.app). Needs ext-curl and ext-json only.

declare(strict_types=1);

namespace Matheel;

/** A client for the Matheel API; the key is optional on the sandbox. */
class Matheel
{
    public const VERSION = '0.3.0';
    public const DEFAULT_BASE_URL = 'https://matheel.app';

    /** 422 codes that are the document's own fault: fix the input, never resend it unchanged. */
    private const PERMANENT = ['zatca_rejected', 'invalid', 'missing_fields', 'invalid_amount', 'validation_failed'];

    private const TELR_FIELDS = [
        'tran_store', 'tran_type', 'tran_class', 'tran_test', 'tran_ref', 'tran_prevref', 'tran_firstref', 'tran_order',
        'tran_currency', 'tran_amount', 'tran_cartid', 'tran_desc', 'tran_status', 'tran_authcode', 'tran_authmessage',
    ];

    /** @var string|null */
    private $key;
    /** @var string */
    private $baseUrl;
    /** @var int */
    private $timeout;

    /** Creates a client; `$timeout` bounds each call in seconds. */
    public function __construct(?string $key = null, string $baseUrl = self::DEFAULT_BASE_URL, int $timeout = 60)
    {
        $this->key = $key ?: null;
        $this->baseUrl = rtrim($baseUrl ?: self::DEFAULT_BASE_URL, '/');
        $this->timeout = $timeout;
    }

    /**
     * POSTs JSON and returns [status, parsed body, Retry-After seconds or null]; status 0 on a network failure.
     * Tests override this to mock HTTP (the third element may be left out).
     */
    protected function send(string $path, array $body): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: matheel-php/' . self::VERSION];
        if ($this->key) {
            $headers[] = 'Authorization: Bearer ' . $this->key;
        }
        $retryAfter = null;
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$retryAfter): int {
                if (stripos($line, 'retry-after:') === 0) {
                    $retryAfter = (int) trim(substr($line, 12));
                }
                return strlen($line);
            },
        ]);
        // Some networks between a cloud host and Matheel's edge drop the large post-quantum ClientHello that
        // OpenSSL 3.5 sends by default (measured 2026-11-17: PHP curl failed, the same call with X25519 succeeded).
        // The option exists from PHP 8.2; older PHP ships older OpenSSL, which never sends that key share.
        if (defined('CURLOPT_SSL_EC_CURVES')) {
            curl_setopt($ch, constant('CURLOPT_SSL_EC_CURVES'), 'X25519:P-256:P-384');
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) {
            $error = curl_error($ch);
            return [0, ['error' => 'network_error', 'message' => $error], null];
        }
        // No curl_close: it does nothing since PHP 8.0 and is deprecated in 8.5; the handle is freed with $ch.
        $parsed = $raw === '' ? [] : json_decode($raw, true);
        return [$status, is_array($parsed) ? $parsed : ['error' => 'invalid_json', 'raw' => $raw], $retryAfter];
    }

    /** Waits before a retry; tests override it so they never sleep. */
    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /** POSTs JSON to an API path and returns the parsed body; throws MatheelError on any status but 200. */
    public function post(string $path, array $body): array
    {
        $answer = $this->send($path, $body);
        [$status, $parsed] = $answer;
        if ($status !== 200) {
            throw new MatheelError($status, $parsed, $answer[2] ?? null);
        }
        return $parsed;
    }

    /**
     * Files one invoice (POST /api/v1/invoices) and returns ZATCA's answer.
     * Keys: `deviceToken`, `invoice`, `pdf`; any other key (environment, seller, csid, mode) passes through.
     */
    public function fileInvoice(array $params): array
    {
        $body = $params;
        unset($body['deviceToken'], $body['pdf']);
        if (!empty($params['deviceToken'])) {
            $body['device_token'] = $params['deviceToken'];
        }
        if (isset($params['pdf'])) {
            $body['pdf'] = (bool) $params['pdf'];
        }
        return $this->post('/api/v1/invoices', $body);
    }

    /**
     * Files with the token in `$store` and returns the 200 answer, after writing its `device_token`.
     *
     * `$options`: `uuid` (the document's stored UUID v4, put into the invoice when it has none; one is generated
     * otherwise, so keep the invoice's uuid and send it on every attempt), `retries` (default 3), `pdf` and any
     * fileInvoice key. 409 `device_token_reused` with `latest_device_token`: writes it and sends this invoice again;
     * without it, re-reads the store and retries. 409 `filing_in_progress`: waits Retry-After (at most 10 s) and
     * retries. Anything else (409 `already_filed` included) throws MatheelError with the token untouched; `classify()` says what to do with it.
     * A 200 without `device_token` throws `invalid_response` (status 0): resend the same request.
     */
    public function fileWithStore(TokenStore $store, array $invoice, array $options = []): array
    {
        $retries = $options['retries'] ?? 3;
        if (empty($invoice['uuid'])) {
            $invoice['uuid'] = $options['uuid'] ?? self::uuid4();
        }
        unset($options['retries'], $options['uuid']);
        for ($attempt = 0; ; $attempt++) {
            try {
                $res = $this->fileInvoice(['deviceToken' => $store->read(), 'invoice' => $invoice] + $options);
            } catch (MatheelError $e) {
                if ($attempt >= $retries || $e->status !== 409) {
                    throw $e;
                }
                $latest = $e->body['error']['latest_device_token'] ?? null;
                if ($e->errorCode === 'device_token_reused' && is_string($latest) && $latest !== '') {
                    $store->write($latest);
                    continue;
                }
                if ($e->errorCode === 'device_token_reused') {
                    continue; // an older server: another worker may have saved a newer token since
                }
                if ($e->errorCode === 'filing_in_progress') {
                    $this->pause(max(1, min($e->retryAfter ?? 5, 10)));
                    continue;
                }
                throw $e;
            }
            if (!is_string($res['device_token'] ?? null) || $res['device_token'] === '') {
                throw new MatheelError(0, ['error' => ['code' => 'invalid_response', 'message' => '200 without device_token'], 'answer' => $res]);
            }
            $store->write($res['device_token']);
            return $res;
        }
    }

    /**
     * What to do with a failed filing: `retryable` (send the same request later: same token source, same uuid),
     * `permanent` (the document is wrong: fix it; the token is unchanged), `needs_attention` (not the document's
     * fault, e.g. a bad key, an expired CSID or no production plan: keep it queued, alert a person, retry later) or
     * `already_filed` (ZATCA accepted this uuid earlier, `error.counter` and `error.zatca_uuid`: it is filed, never resend it).
     */
    public static function classify(MatheelError $e): string
    {
        if ($e->errorCode === 'already_filed') {
            return 'already_filed';
        }
        if ($e->status === 0 || $e->status === 409 || $e->status === 429 || $e->status >= 500 || $e->errorCode === 'filing_failed') {
            return 'retryable';
        }
        if ($e->status === 422 && in_array($e->errorCode, self::PERMANENT, true)) {
            return 'permanent';
        }
        return 'needs_attention';
    }

    /** A random UUID v4: generate one per business document, store it, and send it on every attempt. */
    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
        $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /** Registers a device with ZATCA (POST /api/v1/onboarding): keys `environment`, `seller`, optional `device`, `otp`. */
    public function onboard(array $params): array
    {
        return $this->post('/api/v1/onboarding', array_filter($params, function ($v) {
            return $v !== null;
        }));
    }

    /** Makes a merchant activation link (POST /api/v1/onboarding/links): `environment`, `reference`, optional `callbackUrl`, `expiresInDays`, `maxDevices`. */
    public function activationLink(array $params): array
    {
        $map = ['callbackUrl' => 'callback_url', 'expiresInDays' => 'expires_in_days', 'maxDevices' => 'max_devices'];
        $body = [];
        foreach ($params as $name => $value) {
            if ($value !== null) {
                $body[$map[$name] ?? $name] = $value;
            }
        }
        return $this->post('/api/v1/onboarding/links', $body);
    }

    /** Decodes the base64 TLV ZATCA QR (`qr` in Matheel's answer). */
    public static function decodeQr(string $base64Tlv): QrData
    {
        $bytes = base64_decode(trim($base64Tlv), true);
        if ($bytes === false) {
            throw new \InvalidArgumentException('Not base64');
        }
        $n = strlen($bytes);
        $tags = [];
        $i = 0;
        while ($i + 2 <= $n) {
            $tag = ord($bytes[$i++]);
            $length = ord($bytes[$i++]);
            if ($length & 0x80) {
                // BER long form: the low bits count the length bytes that follow (keys and signatures exceed 127 bytes).
                $count = $length & 0x7f;
                $length = 0;
                for ($k = 0; $k < $count && $i < $n; $k++) {
                    $length = ($length << 8) | ord($bytes[$i++]);
                }
            }
            if ($i + $length > $n) {
                throw new \InvalidArgumentException('Truncated ZATCA QR');
            }
            $tags[$tag] = (string) substr($bytes, $i, $length);
            $i += $length;
        }
        if (($tags[1] ?? '') === '' || ($tags[2] ?? '') === '') {
            throw new \InvalidArgumentException('Not a ZATCA QR: tags 1 and 2 are required');
        }
        return new QrData($tags);
    }

    /** True when `x-matheel-signature` ("sha256=<hex>") is the HMAC-SHA256 of the raw callback body with `callback_secret`. */
    public static function verifyActivationCallback(string $rawBody, ?string $signatureHeader, string $callbackSecret): bool
    {
        if ($callbackSecret === '' || !$signatureHeader) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $callbackSecret);
        return hash_equals($expected, strtolower(trim($signatureHeader)));
    }

    /** True when Telr's `tran_check` matches the SHA1 of the secret and the transaction fields (tran_order only when sent). */
    public static function verifyTelr(array $postFields, string $secret): bool
    {
        if ($secret === '' || empty($postFields['tran_check'])) {
            return false;
        }
        $parts = [$secret];
        foreach (self::TELR_FIELDS as $name) {
            if ($name === 'tran_order' && !array_key_exists($name, $postFields)) {
                continue;
            }
            $parts[] = (string) ($postFields[$name] ?? '');
        }
        return hash_equals(sha1(implode(':', $parts)), strtolower(trim((string) $postFields['tran_check'])));
    }
}
