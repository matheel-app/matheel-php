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

Generate a UUID v4 once per business document (`Matheel::uuid4()`), store it with the order and pass it on every
attempt (`['uuid' => $order->invoice_uuid]`): that is what makes a retry safe. On 200 it writes the new token (a 200
without one throws `invalid_response`, status 0: resend). On `409 device_token_reused` it writes the
`latest_device_token` from the error and sends the invoice again; on `409 filing_in_progress` it waits Retry-After (at
most 10 s) and retries (3 times in all). Anything else leaves the token alone and throws `Matheel\MatheelError`
(`status`, `body`, `errorCode`); `Matheel::classify($e)` says what to do:

| `classify` | Errors | Do |
|---|---|---|
| `retryable` | network (status 0), `invalid_response`, 429, 5xx (504 `filing_outcome_unknown`, 503 `zatca_unavailable`), 409 `previous_filing_unknown`, 422 `filing_failed` | Send the same request later: same uuid. A document ZATCA already accepted comes back as a 200 with `"replayed": true`, never filed twice. Resend an unanswered document before any newer one. |
| `permanent` | 422 `zatca_rejected`, `invalid`, `missing_fields`, `invalid_amount`, `validation_failed` | Fix the document; the token is unchanged. |
| `needs_attention` | 401 `invalid_api_key` / `invalid_csid`, 402 `production_plan_required`, other 4xx | Not the document's fault: keep it, alert a person, retry later. |
| `already_filed` | 409 `already_filed` (`error.counter`, `error.zatca_uuid`) | ZATCA accepted this uuid earlier: it is filed. Never resend it; keep the copy you saved then. |

## Payments and refunds, done for you: `PdoQueue`

One table does the whole job a payment app needs: one invoice per payment (a gateway that notifies twice never makes
two), credit notes for full or partial refunds linked to the original invoice, automatic retries when ZATCA or Matheel
is down, and the legal XML kept in the database (plus XML and PDF copies on disk). MySQL, PostgreSQL or SQLite.

```php
use Matheel\Matheel;
use Matheel\PdoQueue;

$queue = new PdoQueue($pdo, new Matheel(getenv('MATHEEL_KEY')), '/var/invoices');
$queue->install();                 // once, and after every upgrade: creates or migrates the tables
$queue->setToken($deviceToken);    // once: the device_token from onboarding

// After the gateway confirms a payment (Telr: verify it with Matheel::verifyTelr first):
$row = $queue->invoice($tranRef, [
    'type' => 'simplified',
    'prices_include_vat' => true,
    'line_items' => [['name' => 'Renewal', 'quantity' => 1, 'amount' => '172.50']],
]);
// $row['status']: reported | cleared | queued (filed later) | rejected (see $row['error']) | attention
// $row['invoice_xml'] (the legal XML), $row['qr'], $row['number'], $row['uuid'], $row['pdf_path'], $row['xml_path']

// A full or partial refund: the refunded lines only (refused if the original was rejected).
$queue->refund($refundRef, $tranRef, [['name' => 'Renewal', 'quantity' => 1, 'amount' => '172.50']], 'Customer refund');

// Cron, every 5 minutes: files what is still pending; alert a person on attention().
$queue->process();
foreach ($queue->attention() as $row) { /* email or log $row['ref'], $row['status'], $row['error'] */ }
```

- **uuid.** Each row gets its own UUID v4 when it is queued (or the `uuid` you put in the invoice), and every attempt
  sends it. A credit note gets its own uuid, number and totals: `refund()` drops the original's `number`, `uuid`,
  `issued_at`, `subtotal`, `vat_amount`, `total` and `counter`.
- **Statuses.** `queued`: filed by the next `process()` with the same request. `reported` / `cleared`: accepted (a
  `409 already_filed` counts too, see below). `rejected`: only for `zatca_rejected`, `invalid`, `missing_fields`,
  `invalid_amount` and `validation_failed`; fix the data and queue it again under a new reference. `attention`: a bad
  key or CSID (401) or no production plan (402); still retried by `process()`, never marked rejected.
- **`attention($stuckHours = 12)`** lists rows a person must look at: status `attention`, rows still `queued` after 12
  hours (simplified invoices must reach ZATCA within 24), and accepted rows with no saved XML.
- **Order and recovery.** Filing runs under a lock on the device row; the new token and the row's answer commit in one
  transaction, and the files on disk are written after commit (a full disk loses no token). An answer that never
  arrived (network, 5xx, 409) stops the run: the next `process()` resends that exact request first and gets ZATCA's
  stored answer back (`replayed`). A credit note waits for its original; rows refused before reaching ZATCA (503
  `zatca_unavailable`) do not hold up the rows after them. `invoice()` and `refund()` file the new row at once only when
  nothing older is pending; otherwise cron files it in order. If ZATCA accepted a document whose answer was lost and the
  chain has moved on, the server answers `409 already_filed`: the row becomes `reported` with ZATCA's uuid and, having no
  XML, shows up in `attention()`.
- **Transactions.** Give the queue a connection with no open transaction (it throws `LogicException` otherwise): it
  commits its own work, so a rollback of yours can never undo a filing ZATCA already has. Database errors propagate and
  roll back; only API answers are recorded on the row.

Simplified invoices may be reported up to 24 hours after issue, so an outage only delays them. Tested on PHP 7.4 and
8.3, SQLite and PostgreSQL, against ZATCA's developer sandbox (invoice, duplicate notification, replayed answer, stale
token, partial refund): `php tests/queue.php` (`MATHEEL_OFFLINE=1` for the offline part only).

## API

| | |
|---|---|
| `new Matheel(?string $key = null, string $baseUrl = 'https://matheel.app', int $timeout = 60)` | The client. |
| `fileInvoice(['deviceToken' => …, 'invoice' => […], 'pdf' => true])` | `POST /api/v1/invoices`. Other keys (`environment`, `seller`, `csid`, `mode`) pass through. |
| `fileWithStore(TokenStore $store, array $invoice, ['uuid' => …, 'pdf' => true, 'retries' => 3])` | The safe chain helper above. |
| `Matheel::classify(MatheelError $e)` | `retryable`, `permanent`, `needs_attention` or `already_filed` (table above). |
| `Matheel::uuid4()` | A random UUID v4 for a new document. |
| `onboard(['environment' => …, 'seller' => […], 'device' => […], 'otp' => …])` | `POST /api/v1/onboarding`, returns `device_token`. |
| `activationLink(['environment' => …, 'reference' => …, 'callbackUrl' => …, 'expiresInDays' => …, 'maxDevices' => …])` | `POST /api/v1/onboarding/links`. |
| `Matheel::decodeQr($qr)` | `QrData` with `sellerName`, `vatNumber`, `timestamp`, `total`, `vatAmount`, `invoiceHash`, `signature`, `publicKey`, `certificateSignature` (tags 8 and 9 as base64). |
| `Matheel::verifyActivationCallback(file_get_contents('php://input'), $_SERVER['HTTP_X_MATHEEL_SIGNATURE'] ?? null, $callbackSecret)` | Checks an activation callback (HMAC-SHA256, `hash_equals`). |
| `Matheel::verifyTelr($_POST, $telrSecret)` | Checks Telr's `tran_check` on a transaction advice. |

Every non-200 answer throws `MatheelError`; `errorCode` is the API's error code (`device_token_reused`,
`sandbox_rate_limited`, `invalid_device_token`, …), `zatca_rejected` when ZATCA's `errors` are in the body, or
`network_error` (status 0); `retryAfter` holds the Retry-After seconds when sent. Keep the key and the device token on your server, never in an app.

Tests: `php tests/run.php` (one live sandbox filing; `MATHEEL_OFFLINE=1` skips it).

Docs: https://matheel.app/en/docs · PHP guide: https://matheel.app/en/zatca-integration/php ·
API reference for agents: https://matheel.app/llms.txt

## عربي

مكتبة ماثل الرسمية لـ PHP: أرسل فاتورة زاتكا (المرحلة الثانية) بطلب واحد، ويرجع لك رد الهيئة والـ XML والـ QR والـ PDF.
بعد الدفع استخدم `fileWithStore` مع قفل صف الجهاز في قاعدة بياناتك (`SELECT … FOR UPDATE`)، وهي تحفظ `device_token`
الجديد بعد كل فاتورة مقبولة. ولّد `uuid` واحد لكل فاتورة واحفظه وأرسله في كل محاولة، فإعادة الإرسال آمنة ولا تُسجَّل الفاتورة مرتين. بيئة التجربة ما تحتاج مفتاح. الدليل: [matheel.app/rabt](https://matheel.app/rabt)
