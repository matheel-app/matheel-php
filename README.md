# matheel/matheel-php

Official PHP client for [Matheel](https://matheel.app), the **ZATCA (Fatoora) Phase 2** e-invoicing API for Saudi
Arabia. One call signs the invoice, files it with ZATCA (reporting or clearance) and returns ZATCA's answer, the legal
XML, the QR and a bilingual PDF. PHP 7.4+, needs only `ext-curl` and `ext-json`.

```sh
composer require matheel/matheel-php
```

```php
use Matheel\Matheel;

$matheel = new Matheel(getenv('MATHEEL_KEY') ?: null); // no key needed on the sandbox

// Try it now: the keyless sandbox files as Matheel's shared test taxpayer.
$res = $matheel->fileInvoice([
    'environment' => 'sandbox',
    'pdf' => true,
    'invoice' => ['type' => 'simplified', 'line_items' => [['name' => 'Iqama renewal service', 'quantity' => 1, 'price' => '100.00']]],
]);
$res['status'];                // "reported"
base64_decode($res['pdf']);    // the PDF/A-3 invoice
```

## After a payment: `fileWithStore`

Register the device once (`onboard`, or an `activationLink` for a merchant), keep its `device_token` in one database
row, and file every paid order through `fileWithStore`. The token carries the invoice chain (counter and hash), so
invoices of one device must be filed one after another: read and write the token under a row lock.

```php
// CREATE TABLE matheel_device (id INT PRIMARY KEY, token TEXT NOT NULL);
final class DeviceRow implements Matheel\TokenStore
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }
    public function read(): string
    {
        return $this->db->query('SELECT token FROM matheel_device WHERE id = 1 FOR UPDATE')->fetchColumn();
    }
    public function write(string $token): void
    {
        $this->db->prepare('UPDATE matheel_device SET token = ? WHERE id = 1')->execute([$token]);
    }
}

function invoice_order(PDO $db, Matheel $matheel, array $items): array
{
    $db->beginTransaction();
    try {
        $res = $matheel->fileWithStore(new DeviceRow($db), ['type' => 'simplified', 'line_items' => $items], ['pdf' => true]);
        $db->commit();
        return $res; // save base64_decode($res['pdf']) and $res['invoice_xml'] with the order
    } catch (Throwable $e) {
        $db->rollBack(); // the old token stays valid
        throw $e;
    }
}
```

On 200 it writes the new token. On `409 device_token_reused` it re-reads the token and retries (3 times). On any other
error it leaves the token alone and throws `Matheel\MatheelError` (`status`, `body`, `errorCode`): fix the invoice and
resend.

## Payments and refunds, done for you: `PdoQueue`

One table does the whole job a payment app needs: one invoice per payment (a gateway that notifies twice never makes
two), credit notes for full or partial refunds linked to the original invoice, automatic retries in order when ZATCA or
Matheel is down, and the legal XML plus the PDF archived on disk. MySQL, PostgreSQL or SQLite.

```php
use Matheel\Matheel;
use Matheel\PdoQueue;

$queue = new PdoQueue($pdo, new Matheel(getenv('MATHEEL_KEY')), '/var/invoices');
$queue->install();                 // once: creates matheel_device and matheel_invoices
$queue->setToken($deviceToken);    // once: the device_token from onboarding

// After the gateway confirms a payment (Telr: verify it with Matheel::verifyTelr first):
$row = $queue->invoice($tranRef, [
    'type' => 'simplified',
    'prices_include_vat' => true,
    'line_items' => [['name' => 'Renewal', 'quantity' => 1, 'amount' => '172.50']],
]);
// $row['status']: reported | cleared | queued (ZATCA down: filed later) | rejected (see $row['error'])
// $row['pdf_path'], $row['xml_path'], $row['qr'], $row['number']

// A full or partial refund: the refunded lines only.
$queue->refund($refundRef, $tranRef, [['name' => 'Renewal', 'quantity' => 1, 'amount' => '172.50']], 'Customer refund');

// Cron, every 5 minutes: files whatever is still queued, oldest first.
$queue->process();
```

Simplified invoices may be reported up to 24 hours after issue, so an outage only delays them. Tested on PHP 7.4 and
8.3 against ZATCA's developer sandbox (invoice, duplicate notification, partial refund).

## API

| | |
|---|---|
| `new Matheel(?string $key = null, string $baseUrl = 'https://matheel.app', int $timeout = 60)` | The client. |
| `fileInvoice(['deviceToken' => …, 'invoice' => […], 'pdf' => true])` | `POST /api/v1/invoices`. Other keys (`environment`, `seller`, `csid`, `mode`) pass through. |
| `fileWithStore(TokenStore $store, array $invoice, ['pdf' => true, 'retries' => 3])` | The safe chain helper above. |
| `onboard(['environment' => …, 'seller' => […], 'device' => […], 'otp' => …])` | `POST /api/v1/onboarding`, returns `device_token`. |
| `activationLink(['environment' => …, 'reference' => …, 'callbackUrl' => …, 'expiresInDays' => …, 'maxDevices' => …])` | `POST /api/v1/onboarding/links`. |
| `Matheel::decodeQr($qr)` | `QrData` with `sellerName`, `vatNumber`, `timestamp`, `total`, `vatAmount`, `invoiceHash`, `signature`, `publicKey`, `certificateSignature` (tags 8 and 9 as base64). |
| `Matheel::verifyActivationCallback(file_get_contents('php://input'), $_SERVER['HTTP_X_MATHEEL_SIGNATURE'] ?? null, $callbackSecret)` | Checks an activation callback (HMAC-SHA256, `hash_equals`). |
| `Matheel::verifyTelr($_POST, $telrSecret)` | Checks Telr's `tran_check` on a transaction advice. |

Every non-200 answer throws `MatheelError`; `errorCode` is the API's error code (`device_token_reused`,
`sandbox_rate_limited`, `invalid_device_token`, …), `zatca_rejected` when ZATCA's `errors` are in the body, or
`network_error` (status 0). Keep the key and the device token on your server, never in an app.

Tests: `php tests/run.php` (one live sandbox filing; `MATHEEL_OFFLINE=1` skips it).

Docs: https://matheel.app/en/docs · PHP guide: https://matheel.app/en/zatca-integration/php ·
API reference for agents: https://matheel.app/llms.txt

## عربي

مكتبة ماثل الرسمية لـ PHP: أرسل فاتورة زاتكا (المرحلة الثانية) بطلب واحد، ويرجع لك رد الهيئة والـ XML والـ QR والـ PDF.
بعد الدفع استخدم `fileWithStore` مع قفل صف الجهاز في قاعدة بياناتك (`SELECT … FOR UPDATE`)، وهي تحفظ `device_token`
الجديد بعد كل فاتورة مقبولة. بيئة التجربة ما تحتاج مفتاح. الدليل: [matheel.app/rabt](https://matheel.app/rabt)
