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

    public function __construct(array $answers, ?string $key = null)
    {
        parent::__construct($key);
        $this->answers = $answers;
    }

    protected function send(string $path, array $body): array
    {
        $this->calls[] = [$path, $body];
        return array_shift($this->answers);
    }
}

/** An in-memory TokenStore that counts reads and writes. */
class MemoryStore implements TokenStore
{
    public $token;
    public $reads = 0;
    public $writes = [];

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    public function read(): string
    {
        $this->reads++;
        return $this->token;
    }

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

check('fileWithStore: 409 then 200 re-reads the token, writes the new one once', function () {
    $m = new MockMatheel([[409, ['error' => ['code' => 'device_token_reused']]], [200, ['status' => 'reported', 'device_token' => 'mdt1.new']]]);
    $store = new MemoryStore('mdt1.old');
    $res = $m->fileWithStore($store, ['type' => 'simplified'], ['pdf' => true]);
    eq('reported', $res['status']);
    eq(['mdt1.new'], $store->writes);
    eq(2, $store->reads);
    eq(['/api/v1/invoices', ['invoice' => ['type' => 'simplified'], 'device_token' => 'mdt1.old', 'pdf' => true]], $m->calls[1]);
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
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
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
