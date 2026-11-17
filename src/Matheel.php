<?php
// Official PHP client for the Matheel ZATCA Phase 2 e-invoicing API (https://matheel.app). Needs ext-curl and ext-json only.

declare(strict_types=1);

namespace Matheel;

/** A client for the Matheel API; the key is optional on the sandbox. */
class Matheel
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://matheel.app';

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

    /** POSTs JSON and returns [status, parsed body]; status 0 on a network failure. Tests override this to mock HTTP. */
    protected function send(string $path, array $body): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: matheel-php/' . self::VERSION];
        if ($this->key) {
            $headers[] = 'Authorization: Bearer ' . $this->key;
        }
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
            return [0, ['error' => 'network_error', 'message' => $error]];
        }
        // No curl_close: it does nothing since PHP 8.0 and is deprecated in 8.5; the handle is freed with $ch.
        $parsed = $raw === '' ? [] : json_decode($raw, true);
        return [$status, is_array($parsed) ? $parsed : ['error' => 'invalid_json', 'raw' => $raw]];
    }

    /** POSTs JSON to an API path and returns the parsed body; throws MatheelError on any status but 200. */
    public function post(string $path, array $body): array
    {
        [$status, $parsed] = $this->send($path, $body);
        if ($status !== 200) {
            throw new MatheelError($status, $parsed);
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
     * Files with the token in `$store`: writes the new token on 200, re-reads and retries on 409 device_token_reused
     * (up to `$options['retries']`, default 3), leaves the token untouched on any other error (and throws).
     */
    public function fileWithStore(TokenStore $store, array $invoice, array $options = []): array
    {
        $retries = $options['retries'] ?? 3;
        unset($options['retries']);
        for ($attempt = 0; ; $attempt++) {
            try {
                $res = $this->fileInvoice(['deviceToken' => $store->read(), 'invoice' => $invoice] + $options);
            } catch (MatheelError $e) {
                if ($e->status === 409 && $e->errorCode === 'device_token_reused' && $attempt < $retries) {
                    continue;
                }
                throw $e;
            }
            if (!empty($res['device_token'])) {
                $store->write($res['device_token']);
            }
            return $res;
        }
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
