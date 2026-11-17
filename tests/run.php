<?php
// Plain-PHP test runner (no PHPUnit needed): offline checks, then one live filing on the keyless sandbox (MATHEEL_OFFLINE=1 skips it).

declare(strict_types=1);

spl_autoload_register(function ($class) {
    if (strpos($class, 'Matheel\\') === 0) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    }
});

use Matheel\Matheel;
use Matheel\MatheelError;
use Matheel\TokenStore;

$passed = 0;
$failed = 0;

/** Runs one named test and counts the result. */
function check(string $name, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        $passed++;
        echo "ok   $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL $name: " . $e->getMessage() . ' (line ' . $e->getLine() . ")\n";
    }
}

/** Throws unless $actual === $expected. */
function eq($expected, $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new Exception("$what expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** Returns the exception $fn throws, or fails. */
function throws(callable $fn): Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }
    throw new Exception('expected an exception');
}

/** One TLV field, with a BER long-form length when the value exceeds 127 bytes. */
function tlv(int $tag, string $value): string
{
    $len = strlen($value);
    return chr($tag) . ($len < 128 ? chr($len) : chr(0x81) . chr($len)) . $value;
}

/** A client whose transport answers from a queue of [status, body] and records each request. */
class MockMatheel extends Matheel
{
    public $answers;
    public $calls = [];
    public $slept = [];

    /** Queues the scripted answers. */
    public function __construct(array $answers, ?string $key = null)
    {
        parent::__construct($key);
        $this->answers = $answers;
    }

    /** Records the request and returns the next scripted answer. */
    protected function send(string $path, array $body): array
    {
        $this->calls[] = [$path, $body];
        return array_shift($this->answers);
    }

    /** Records the wait instead of sleeping. */
    protected function pause(int $seconds): void
    {
        $this->slept[] = $seconds;
    }
}

/** An in-memory TokenStore that counts reads and writes. */
class MemoryStore implements TokenStore
{
    public $token;
    public $reads = 0;
    public $writes = [];

    /** Starts with `$token`. */
    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /** Returns the token and counts the read. */
    public function read(): string
    {
        $this->reads++;
        return $this->token;
    }

    /** Saves the token and records the write. */
    public function write(string $token): void
    {
        $this->writes[] = $token;
        $this->token = $token;
    }
}

check('decodeQr reads tags 1-9, Arabic text and long-form lengths', function () {
    $key = str_repeat(chr(7), 136);
    $qr = base64_encode(tlv(1, 'شركة ماثل') . tlv(2, '399999999900003') . tlv(3, '2026-11-16T10:00:00') . tlv(4, '115.00')
        . tlv(5, '15.00') . tlv(6, 'aGFzaA==') . tlv(7, 'c2ln') . tlv(8, $key) . tlv(9, "\x01\x02\x03"));
    $d = Matheel::decodeQr($qr);
    eq('شركة ماثل', $d->sellerName);
    eq('399999999900003', $d->vatNumber);
    eq(['2026-11-16T10:00:00', '115.00', '15.00'], [$d->timestamp, $d->total, $d->vatAmount]);
    eq(['aGFzaA==', 'c2ln'], [$d->invoiceHash, $d->signature]);
    eq($key, base64_decode($d->publicKey));
    eq('AQID', $d->certificateSignature);
});

check('decodeQr rejects truncated and non-ZATCA input', function () {
    $full = tlv(1, 'Seller') . tlv(2, '399999999900003');
    eq('Truncated ZATCA QR', throws(function () use ($full) { Matheel::decodeQr(base64_encode(substr($full, 0, -3))); })->getMessage());
    eq(true, strpos(throws(function () { Matheel::decodeQr(base64_encode(tlv(3, 'x'))); })->getMessage(), 'tags 1 and 2') !== false);
});

check('verifyActivationCallback accepts the right HMAC only', function () {
    $body = json_encode(['reference' => 'm-1', 'devices' => [['device_token' => 'mdt1.x']]]);
    $sig = 'sha256=' . hash_hmac('sha256', $body, 's3cret');
    eq(true, Matheel::verifyActivationCallback($body, $sig, 's3cret'));
    eq(true, Matheel::verifyActivationCallback($body, 'sha256=' . strtoupper(substr($sig, 7)), 's3cret'));
    eq(false, Matheel::verifyActivationCallback($body . ' ', $sig, 's3cret'));
    eq(false, Matheel::verifyActivationCallback($body, $sig, 'other'));
    eq(false, Matheel::verifyActivationCallback($body, null, 's3cret'));
    eq(false, Matheel::verifyActivationCallback($body, $sig, ''));
});

check('verifyTelr checks tran_check with and without tran_order', function () {
    $f = [
        'tran_store' => '12345', 'tran_type' => 'sale', 'tran_class' => 'ecom', 'tran_test' => '1', 'tran_ref' => '040029158825',
        'tran_prevref' => '', 'tran_firstref' => '040029158825', 'tran_currency' => 'SAR', 'tran_amount' => '115.00',
        'tran_cartid' => 'order-9', 'tran_desc' => 'Iqama renewal', 'tran_status' => 'A', 'tran_authcode' => '123456',
        'tran_authmessage' => 'Authorised',
    ];
    $head = ['tran_store', 'tran_type', 'tran_class', 'tran_test', 'tran_ref', 'tran_prevref', 'tran_firstref'];
    $tail = ['tran_currency', 'tran_amount', 'tran_cartid', 'tran_desc', 'tran_status', 'tran_authcode', 'tran_authmessage'];
    $pick = function (array $keys) use ($f) {
        return array_map(function ($k) use ($f) { return $f[$k]; }, $keys);
    };
    $without = $f + ['tran_check' => strtoupper(sha1(implode(':', array_merge(['secret'], $pick($head), $pick($tail)))))];
    eq(true, Matheel::verifyTelr($without, 'secret'));
    eq(false, Matheel::verifyTelr(['tran_amount' => '1.00'] + $without, 'secret'));
    eq(false, Matheel::verifyTelr($without, 'wrong'));
    $withOrder = $f + ['tran_order' => 'ORD1'];
    $withOrder['tran_check'] = sha1(implode(':', array_merge(['secret'], $pick($head), ['ORD1'], $pick($tail))));
    eq(true, Matheel::verifyTelr($withOrder, 'secret'));
    eq(false, Matheel::verifyTelr($f, 'secret'));
});

check('fileWithStore: 409 without latest_device_token (older server) re-reads the token, writes the new one once', function () {
    $m = new MockMatheel([[409, ['error' => ['code' => 'device_token_reused']]], [200, ['status' => 'reported', 'device_token' => 'mdt1.new']]]);
    $store = new MemoryStore('mdt1.old');
    $res = $m->fileWithStore($store, ['type' => 'simplified'], ['pdf' => true, 'uuid' => 'u-1']);
    eq('reported', $res['status']);
    eq(['mdt1.new'], $store->writes);
    eq(2, $store->reads);
    eq(['/api/v1/invoices', ['invoice' => ['type' => 'simplified', 'uuid' => 'u-1'], 'device_token' => 'mdt1.old', 'pdf' => true]], $m->calls[1]);
});

check('fileWithStore: 409 with latest_device_token stores it and sends the same invoice again with it', function () {
    $stale = ['error' => ['code' => 'device_token_reused', 'latest_device_token' => 'mdt1.latest', 'latest_counter' => 7]];
    $m = new MockMatheel([[409, $stale], [200, ['status' => 'reported', 'device_token' => 'mdt1.next', 'counter' => 8]]]);
    $store = new MemoryStore('mdt1.old');
    $m->fileWithStore($store, ['type' => 'simplified']);
    eq(['mdt1.latest', 'mdt1.next'], $store->writes);
    eq(['mdt1.old', 'mdt1.latest'], [$m->calls[0][1]['device_token'], $m->calls[1][1]['device_token']]);
    eq(1, preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $m->calls[0][1]['invoice']['uuid']));
    eq($m->calls[0][1]['invoice']['uuid'], $m->calls[1][1]['invoice']['uuid'], 'one uuid for every attempt:');
});

check('fileWithStore: filing_in_progress waits Retry-After (at most 10 s) and retries', function () {
    $busy = ['error' => ['code' => 'filing_in_progress']];
    $m = new MockMatheel([[409, $busy, 3], [409, $busy, 60], [200, ['status' => 'reported', 'device_token' => 'mdt1.new']]]);
    $m->fileWithStore(new MemoryStore('mdt1.old'), ['uuid' => 'u-2']);
    eq([3, 10], $m->slept);
    eq(3, count($m->calls));
});

check('fileWithStore: 409 already_filed is not retried and leaves the token alone', function () {
    $m = new MockMatheel([[409, ['error' => ['code' => 'already_filed', 'counter' => 3, 'zatca_uuid' => 'z-3']]]]);
    $store = new MemoryStore('mdt1.old');
    $e = throws(function () use ($m, $store) { $m->fileWithStore($store, ['uuid' => 'u-3']); });
    eq(['already_filed', 1, []], [$e->errorCode, count($m->calls), $store->writes]);
});

check('fileWithStore: a 200 without device_token throws invalid_response and writes nothing', function () {
    $m = new MockMatheel([[200, ['status' => 'reported']]]);
    $store = new MemoryStore('mdt1.old');
    $e = throws(function () use ($m, $store) { $m->fileWithStore($store, []); });
    eq([0, 'invalid_response', 'retryable'], [$e->status, $e->errorCode, Matheel::classify($e)]);
    eq([], $store->writes);
});

check('classify follows the filing contract', function () {
    $cases = [
        [0, ['error' => 'network_error'], 'retryable'], [504, ['error' => ['code' => 'filing_outcome_unknown']], 'retryable'],
        [503, ['error' => ['code' => 'zatca_unavailable']], 'retryable'], [429, ['error' => 'sandbox_rate_limited'], 'retryable'],
        [409, ['error' => ['code' => 'previous_filing_unknown', 'uuid' => 'x']], 'retryable'], [502, [], 'retryable'],
        [422, ['error' => ['code' => 'filing_failed']], 'retryable'],
        [422, ['status' => 'rejected', 'errors' => [['code' => 'BR-KSA-08']], 'error' => ['code' => 'zatca_rejected']], 'permanent'],
        [422, ['error' => ['code' => 'invalid']], 'permanent'], [422, ['error' => ['code' => 'missing_fields']], 'permanent'],
        [422, ['error' => ['code' => 'invalid_amount']], 'permanent'], [422, ['error' => ['code' => 'validation_failed']], 'permanent'],
        [409, ['error' => ['code' => 'already_filed', 'counter' => 3, 'zatca_uuid' => 'z']], 'already_filed'],
        [401, ['error' => 'invalid_api_key'], 'needs_attention'], [401, ['error' => ['code' => 'invalid_csid']], 'needs_attention'],
        [402, ['error' => 'production_plan_required'], 'needs_attention'], [422, ['error' => ['code' => 'invalid_device_token']], 'needs_attention'],
    ];
    foreach ($cases as [$status, $body, $kind]) {
        eq($kind, Matheel::classify(new MatheelError($status, $body)), "$status " . json_encode($body));
    }
});

check('fileWithStore: a rejection leaves the token untouched and throws a typed error', function () {
    $rejected = ['status' => 'rejected', 'errors' => [['code' => 'BR-KSA-33']]];
    $m = new MockMatheel([[422, $rejected]]);
    $store = new MemoryStore('mdt1.old');
    $e = throws(function () use ($m, $store) { $m->fileWithStore($store, []); });
    eq(true, $e instanceof MatheelError);
    eq([422, 'zatca_rejected', $rejected], [$e->status, $e->errorCode, $e->body]);
    eq([], $store->writes);
});

check('fileWithStore: gives up after 3 retries on repeated 409', function () {
    $m = new MockMatheel(array_fill(0, 5, [409, ['error' => ['code' => 'device_token_reused']]]));
    $store = new MemoryStore('mdt1.old');
    eq('device_token_reused', throws(function () use ($m, $store) { $m->fileWithStore($store, []); })->errorCode);
    eq(4, count($m->calls));
    eq([], $store->writes);
});

check('onboard and activationLink send the documented bodies', function () {
    $m = new MockMatheel([[200, ['device_token' => 'mdt1.a']], [200, ['url' => 'https://matheel.app/activate#x']]]);
    $m->onboard(['environment' => 'sandbox', 'seller' => ['vat_number' => '1'], 'device' => ['name' => 'Till 1'], 'otp' => null]);
    $m->activationLink(['environment' => 'production', 'reference' => 'm-42', 'callbackUrl' => 'https://a.test/cb', 'maxDevices' => 3]);
    eq(['/api/v1/onboarding', ['environment' => 'sandbox', 'seller' => ['vat_number' => '1'], 'device' => ['name' => 'Till 1']]], $m->calls[0]);
    eq(['/api/v1/onboarding/links', ['environment' => 'production', 'reference' => 'm-42', 'callback_url' => 'https://a.test/cb', 'max_devices' => 3]], $m->calls[1]);
});

check('error codes come from a string error', function () {
    $e = throws(function () { (new MockMatheel([[429, ['error' => 'sandbox_rate_limited']]]))->fileInvoice(['invoice' => []]); });
    eq([429, 'sandbox_rate_limited'], [$e->status, $e->errorCode]);
});

if (getenv('MATHEEL_OFFLINE') !== '1') {
    check('live: files a simplified invoice on the keyless sandbox, with PDF and QR', function () {
        $uuid = Matheel::uuid4();
        $res = (new Matheel())->fileInvoice([
            'environment' => 'sandbox',
            'pdf' => true,
            'invoice' => ['type' => 'simplified', 'counter' => 1, 'uuid' => $uuid, 'number' => 'T-1',
                'line_items' => [['name' => 'Iqama renewal service', 'quantity' => 1, 'price' => '100.00']]],
        ]);
        eq('reported', $res['status']);
        eq('%PDF', substr(base64_decode($res['pdf']), 0, 4));
        eq(1, preg_match('/^\d{15}$/', Matheel::decodeQr($res['qr'])->vatNumber));
    });
}

echo "\n$passed passed, $failed failed (PHP " . PHP_VERSION . ")\n";
exit($failed === 0 ? 0 : 1);
