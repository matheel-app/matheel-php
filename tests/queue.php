<?php
// PdoQueue checks: offline with a scripted API (duplicates, outages, lost answers, refunds, rejections, locks, migration),
// then live on the keyless sandbox. SQLite by default; MATHEEL_TEST_DSN (+ _USER, _PASSWORD) runs them on PostgreSQL or MySQL.

declare(strict_types=1);

spl_autoload_register(function ($class) {
    if (strpos($class, 'Matheel\\') === 0) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    }
});

use Matheel\Matheel;
use Matheel\PdoQueue;
use Matheel\TokenStore;

$failed = 0;

/** Asserts one condition and prints it. */
function ok(bool $cond, string $what): void
{
    global $failed;
    echo ($cond ? 'ok   ' : 'FAIL ') . $what . "\n";
    if (!$cond) {
        $failed++;
    }
}

/** Returns the exception $fn throws, or null. */
function thrown(callable $fn): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }
    return null;
}

/** A client whose answers are scripted: each call pops the next [status, body] (or a callable that returns one). */
final class Scripted extends Matheel
{
    public $calls = [];
    public $answers = [];

    /** Records the request and returns the next scripted answer (ZATCA down when none is left). */
    protected function send(string $path, array $body): array
    {
        $this->calls[] = $body;
        $answer = array_shift($this->answers) ?? [503, ['error' => ['code' => 'zatca_unavailable']]];
        return $answer instanceof Closure ? $answer($body) : $answer;
    }

    /** Never sleeps in tests. */
    protected function pause(int $seconds): void
    {
    }

    /** The invoice sent in the last call. */
    public function lastInvoice(): array
    {
        return end($this->calls)['invoice'];
    }
}

/** An in-memory TokenStore for the live checks. */
final class Held implements TokenStore
{
    public $token;

    /** Starts with `$token`. */
    public function __construct(string $token)
    {
        $this->token = $token;
    }

    /** The held token. */
    public function read(): string
    {
        return $this->token;
    }

    /** Replaces the held token. */
    public function write(string $token): void
    {
        $this->token = $token;
    }
}

/** A 200 answer that hands out `$token` as the next device token. */
function accepted(string $token, string $status = 'reported', array $extra = []): array
{
    return [200, $extra + ['status' => $status, 'device_token' => $token, 'zatca_uuid' => 'u-' . $token, 'qr' => 'QR',
        'invoice_xml' => base64_encode('<Invoice/>'), 'pdf' => base64_encode('%PDF-1.7')]];
}

$dir = sys_get_temp_dir() . '/matheel-queue-' . getmypid();
@mkdir($dir, 0700, true);
$dsn = getenv('MATHEEL_TEST_DSN') ?: 'sqlite:' . $dir . '/queue.sqlite';

/** A new connection to the test database (SQLite fails at once on a lock instead of waiting). */
function connect(): PDO
{
    global $dsn;
    $pdo = strpos($dsn, 'sqlite:') === 0
        ? new PDO($dsn, null, null, [PDO::ATTR_TIMEOUT => 0])
        : new PDO($dsn, getenv('MATHEEL_TEST_USER') ?: null, getenv('MATHEEL_TEST_PASSWORD') ?: null);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/** Empty tables, a device token t0 and a scripted client: [queue, api, pdo]. */
function fresh(?string $archive = null): array
{
    global $dir;
    $pdo = connect();
    $pdo->exec('DROP TABLE IF EXISTS matheel_invoices');
    $pdo->exec('DROP TABLE IF EXISTS matheel_device');
    $api = new Scripted('k');
    $q = new PdoQueue($pdo, $api, $archive ?? $dir);
    $q->install();
    $q->setToken('t0');
    return [$q, $api, $pdo];
}

/** The stored device token. */
function token(PDO $pdo): string
{
    return (string) $pdo->query('SELECT token FROM matheel_device WHERE id = 1')->fetchColumn();
}

$lines = [['name' => 'Coffee', 'quantity' => 2, 'price' => '10.00'], ['name' => 'Cake', 'quantity' => 1, 'price' => '20.00']];
$sale = ['type' => 'simplified', 'line_items' => $lines];
echo 'Database: ' . connect()->getAttribute(PDO::ATTR_DRIVER_NAME) . ', PHP ' . PHP_VERSION . "\n";

// The happy path, duplicates, an outage, a refund, a rejection.
[$q, $api, $pdo] = fresh();
$api->answers = [accepted('t1')];
$row = $q->invoice('telr-1', $sale);
ok($row['status'] === 'reported' && $row['number'] === 'INV-1', 'a payment is filed at once and numbered');
ok($api->calls[0]['device_token'] === 't0' && $api->calls[0]['pdf'] === true, 'it used the stored token and asked for the PDF');
ok(strlen((string) $row['uuid']) === 36 && $api->calls[0]['invoice']['uuid'] === $row['uuid'], 'the row has its own uuid and sent it');
ok($row['invoice_xml'] === '<Invoice/>' && token($pdo) === 't1', 'the legal XML is in the database, the new token saved');
ok(is_file((string) $row['xml_path']) && is_file((string) $row['pdf_path']), 'XML and PDF are archived on disk');

$again = $q->invoice('telr-1', $sale);
ok(count($api->calls) === 1 && $again['id'] === $row['id'], 'the same payment notified twice makes no second invoice');

$api->answers = [[503, ['error' => ['code' => 'zatca_unavailable']]]];
$down = $q->invoice('telr-2', $sale);
ok($down['status'] === 'queued' && (int) $down['attempts'] === 1, 'ZATCA down: the invoice waits in the queue');
$api->answers = [accepted('t2')];
$q->process();
ok($q->get('telr-2')['status'] === 'reported' && $api->calls[2]['device_token'] === 't1', 'cron files it later with the newest token');
ok($api->calls[2]['invoice']['uuid'] === $api->calls[1]['invoice']['uuid'], 'and with the same uuid');

$api->answers = [accepted('t3')];
$refund = $q->refund('refund-1', 'telr-1', [['name' => 'Cake', 'quantity' => 1, 'price' => '20.00']], 'Returned');
$sent = $api->lastInvoice();
ok($refund['status'] === 'reported' && $sent['document_type'] === 'credit_note', 'a partial refund files a credit note');
ok($sent['original_invoice_number'] === 'INV-1' && count($sent['line_items']) === 1, 'linked to the original invoice, refunded lines only');
ok($refund['uuid'] !== $row['uuid'] && $sent['uuid'] === $refund['uuid'], 'the credit note has its own uuid');

$api->answers = [[422, ['status' => 'rejected', 'errors' => [['code' => 'BR-KSA-08']], 'error' => ['code' => 'zatca_rejected']]]];
$bad = $q->invoice('telr-3', $sale);
ok($bad['status'] === 'rejected' && strpos($bad['error'], 'BR-KSA-08') !== false && token($pdo) === 't3', 'a ZATCA rejection is marked, not retried, token unchanged');
$e = thrown(function () use ($q) { $q->refund('refund-3', 'telr-3', [], 'x'); });
ok($e instanceof InvalidArgumentException && $q->get('refund-3') === null, 'a refund of a rejected invoice is refused');

$api->answers = [[422, ['error' => ['code' => 'missing_fields', 'fields' => ['line_items']]]]];
ok($q->invoice('telr-4', ['type' => 'simplified'])['status'] === 'rejected', 'missing_fields is permanent: rejected');

// refund() copies the original's data but none of its identity or totals.
[$q, $api] = fresh();
$given = Matheel::uuid4();
$api->answers = [accepted('t1'), accepted('t2')];
$orig = $q->invoice('p-1', $sale + ['number' => 'ORD-9', 'uuid' => $given, 'issued_at' => '2026-11-17T10:00:00Z',
    'subtotal' => '40.00', 'vat_amount' => '6.00', 'total' => '46.00', 'counter' => 3, 'buyer' => ['name' => 'Sara']]);
$q->refund('r-1', 'p-1', [['name' => 'Cake', 'quantity' => 1, 'price' => '20.00']], 'Returned');
$cn = $api->lastInvoice();
ok($orig['uuid'] === $given && $orig['number'] === 'ORD-9', "the caller's own uuid and number are kept");
ok(array_intersect(['issued_at', 'subtotal', 'vat_amount', 'total', 'counter'], array_keys($cn)) === []
    && $cn['number'] === 'CRN-2' && $cn['uuid'] !== $given && $cn['buyer'] === ['name' => 'Sara'], 'the credit note drops number, uuid, issued_at, totals and counter');

// A lost answer: ZATCA accepted it, the response never arrived. The retry sends the identical request and is replayed.
[$q, $api, $pdo] = fresh();
$api->answers = [[0, ['error' => 'network_error', 'message' => 'timeout']]];
$lost = $q->invoice('p-1', $sale);
ok($lost['status'] === 'queued' && token($pdo) === 't0', 'no answer: the row stays queued, the token untouched');
$api->answers = [accepted('t1', 'reported', ['replayed' => true])];
$q->process();
ok($q->get('p-1')['status'] === 'reported' && token($pdo) === 't1', 'the retry is replayed (200 replayed) and saved');
ok($api->calls[1]['invoice']['uuid'] === $api->calls[0]['invoice']['uuid'] && $api->calls[1]['device_token'] === 't0', 'same uuid, same token');

// 409 already_filed: ZATCA accepted this uuid earlier and the answer was lost. Filed, never resent, flagged (no XML).
[$q, $api, $pdo] = fresh();
$api->answers = [[0, ['error' => 'network_error']]];
$q->invoice('p-1', $sale);
$api->answers = [[409, ['error' => ['code' => 'already_filed', 'counter' => 4, 'zatca_uuid' => 'z-4']]]];
$done = $q->process();
$row = $q->get('p-1');
ok($done['filed'] === 1 && $row['status'] === 'reported' && $row['zatca_uuid'] === 'z-4' && $row['number'] === 'INV-1', '409 already_filed: the row is reported with the zatca_uuid from the error');
ok(strpos((string) $row['error'], 'counter 4') !== false && count($q->attention()) === 1 && token($pdo) === 't0', 'no XML saved: listed by attention(), token untouched');
$q->process();
ok(count($api->calls) === 2, 'it is never sent again');

// 409 with latest_device_token: save it and send this invoice again as the next counter.
[$q, $api, $pdo] = fresh();
$api->answers = [[409, ['error' => ['code' => 'device_token_reused', 'latest_device_token' => 'tL', 'latest_counter' => 4]]], accepted('t5')];
$row = $q->invoice('p-1', $sale);
ok($row['status'] === 'reported' && $api->calls[1]['device_token'] === 'tL' && token($pdo) === 't5', '409 stale token: latest saved, invoice sent again, filed');

// 504 outcome unknown, then success on the identical request.
[$q, $api, $pdo] = fresh();
$api->answers = [[504, ['error' => ['code' => 'filing_outcome_unknown']]]];
ok($q->invoice('p-1', $sale)['status'] === 'queued', '504: queued for the same request');
$api->answers = [accepted('t1')];
$q->process();
ok($q->get('p-1')['status'] === 'reported' && $api->calls[1]['invoice'] === $api->calls[0]['invoice']
    && $api->calls[1]['device_token'] === 't0', '504 then 200: the identical invoice (same uuid) with the same token');

// A filing with no answer stops the run: the next row must not take its place in the chain.
[$q, $api] = fresh();
$api->answers = [[0, ['error' => 'network_error']]];
$q->invoice('p-1', $sale);
$q->invoice('p-2', $sale);
ok(count($api->calls) === 1, 'a new payment waits for cron while an older one is unanswered');
$api->answers = [[502, []]];
$done = $q->process();
ok($done === ['filed' => 0, 'rejected' => 0, 'waiting' => 1, 'attention' => 0] && count($api->calls) === 2, 'cron stops at an unanswered filing');

// The archive cannot be written after ZATCA accepted: the token and status are committed anyway.
$blocker = $dir . '/not-a-dir';
file_put_contents($blocker, 'x');
[$q, $api, $pdo] = fresh($blocker . '/archive');
$api->answers = [accepted('t1')];
$row = $q->invoice('p-1', $sale);
ok($row['status'] === 'reported' && token($pdo) === 't1' && $row['invoice_xml'] === '<Invoice/>', 'archive not writable: token, status and XML still saved');
ok($row['xml_path'] === null && $row['pdf_path'] === null, 'and no path is recorded for files never written');

// A database error mid-filing propagates and commits nothing.
[$q, $api, $pdo] = fresh();
$api->answers = [function () use ($pdo) {
    $pdo->exec('DROP TABLE matheel_device'); // inside the queue's transaction: the token write will fail
    return accepted('t1');
}];
$e = thrown(function () use ($q, $sale) { $q->invoice('p-1', $sale); });
ok($e instanceof PDOException, 'a PDOException is not swallowed as an API failure');
// MySQL commits DDL on the spot, so the dropped table cannot come back with the rollback: check it elsewhere.
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
    ok(token($pdo) === 't0' && (int) $q->get('p-1')['attempts'] === 0 && $q->get('p-1')['status'] === 'queued', 'the transaction rolled back: nothing committed');
}

// Unrelated rows go past a waiting row; only the credit note waits for its original.
[$q, $api] = fresh();
$api->answers = [[503, ['error' => ['code' => 'zatca_unavailable']]]];
$q->invoice('a', $sale);
$q->refund('a-refund', 'a', [['name' => 'Cake', 'quantity' => 1, 'price' => '20.00']], 'Returned');
$q->invoice('b', $sale);
ok(count($api->calls) === 1, 'rows queued behind a pending one are left to cron');
$api->answers = [[503, ['error' => ['code' => 'zatca_unavailable']]], accepted('t1')];
$done = $q->process();
ok($q->get('b')['status'] === 'reported' && $q->get('a')['status'] === 'queued' && $q->get('a-refund')['status'] === 'queued', 'b is filed past a (ZATCA down for it) and its waiting credit note');
ok($done === ['filed' => 1, 'rejected' => 0, 'waiting' => 2, 'attention' => 0] && count($api->calls) === 3, 'the credit note made no API call');
$api->answers = [accepted('t2'), accepted('t3')];
$q->process();
ok($q->get('a')['status'] === 'reported' && $q->get('a-refund')['status'] === 'reported', 'next run: a, then its credit note');

// The original is rejected after its credit note was queued: the credit note is rejected too.
[$q, $api] = fresh();
$api->answers = [[503, ['error' => ['code' => 'zatca_unavailable']]]];
$q->invoice('a', $sale);
$q->refund('a-refund', 'a', [], 'Returned');
$api->answers = [[422, ['error' => ['code' => 'invalid', 'message' => 'bad']]]];
$q->process();
$cnRow = $q->get('a-refund');
ok($cnRow['status'] === 'rejected' && strpos((string) $cnRow['error'], 'original invoice') !== false && count($api->calls) === 2, 'a credit note on a rejected original is rejected, never sent');

// 401/402 are not the document's fault: attention, still retried, never rejected.
[$q, $api] = fresh();
$api->answers = [[401, ['error' => 'invalid_api_key']]];
$row = $q->invoice('p-1', $sale);
$list = $q->attention();
ok($row['status'] === 'attention' && count($list) === 1 && $list[0]['ref'] === 'p-1', '401: attention, listed by attention()');
$api->answers = [[402, ['error' => 'production_plan_required']]];
ok($q->process()['attention'] === 1 && $q->get('p-1')['status'] === 'attention', '402: still attention after a retry');
$api->answers = [accepted('t1')];
$q->process();
ok($q->get('p-1')['status'] === 'reported' && $q->attention() === [], 'fixed key: filed by the next run, nothing needs attention');
$old = connect();
$old->prepare("UPDATE matheel_invoices SET status = 'queued', created_at = ? WHERE ref = 'p-1'")->execute([gmdate('c', time() - 13 * 3600)]);
ok(count($q->attention()) === 1, 'a row still queued after 12 hours is reported as stuck');

// setToken takes the filing lock: while another worker files, it waits instead of racing; one row, updated in place.
[$q, $api, $pdo] = fresh();
$other = connect();
if ($other->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $other->exec('BEGIN IMMEDIATE');
} else {
    // Fail fast instead of waiting for the lock: PostgreSQL's lock_timeout, MySQL's innodb_lock_wait_timeout (seconds).
    $pdo->exec($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'SET innodb_lock_wait_timeout = 1' : "SET lock_timeout = '300ms'");
    $other->beginTransaction();
    $other->query('SELECT token FROM matheel_device WHERE id = 1 FOR UPDATE')->fetch();
}
$e = thrown(function () use ($q) { $q->setToken('t-new'); });
$pdo->inTransaction() && $pdo->rollBack();
$other->exec('ROLLBACK');
ok($e instanceof PDOException && token($pdo) === 't0', 'setToken blocks on the device lock held by a filing');
$q->setToken('t-new');
ok(token($pdo) === 't-new' && (int) $pdo->query('SELECT COUNT(*) FROM matheel_device')->fetchColumn() === 1, 'then updates the one device row');

// The queue refuses to run inside a caller's transaction.
$pdo->beginTransaction();
$e1 = thrown(function () use ($q, $sale) { $q->invoice('p-9', $sale); });
$e2 = thrown(function () use ($q) { $q->process(); });
$pdo->rollBack();
ok($e1 instanceof LogicException && $e2 instanceof LogicException, 'invoice() and process() inside an open transaction throw LogicException');

// install() migrates a 0.2.0 table: new columns, and a uuid for each pending row.
$pdo = connect();
$pdo->exec('DROP TABLE IF EXISTS matheel_invoices');
$pdo->exec('CREATE TABLE matheel_invoices (id INTEGER PRIMARY KEY, ref VARCHAR(191) NOT NULL UNIQUE, kind VARCHAR(16) NOT NULL,
    original_ref VARCHAR(191), payload TEXT NOT NULL, status VARCHAR(16) NOT NULL, attempts INT NOT NULL DEFAULT 0, error TEXT,
    number VARCHAR(64), zatca_uuid VARCHAR(64), qr TEXT, xml_path TEXT, pdf_path TEXT, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL)');
$pdo->exec("INSERT INTO matheel_invoices (id, ref, kind, payload, status, created_at, updated_at) VALUES (1, 'old-1', 'invoice', '{}', 'queued', 'x', 'x')");
$api = new Scripted('k');
$q = new PdoQueue($pdo, $api, $dir);
$q->install();
$q->install();
$migrated = $q->get('old-1');
ok(strlen((string) $migrated['uuid']) === 36 && array_key_exists('invoice_xml', $migrated), 'install() adds uuid and invoice_xml to a 0.2.0 table, twice is harmless');

// Live: a device on ZATCA's developer sandbox (keyless), real invoices, a lost answer replayed, a stale token, a refund.
if (getenv('MATHEEL_OFFLINE') !== '1') {
    $live = new Matheel(getenv('MATHEEL_KEY') ?: null, getenv('MATHEEL_BASE_URL') ?: Matheel::DEFAULT_BASE_URL);
    // MATHEEL_DEVICE_TOKEN reuses a sandbox device (keyless onboarding is limited to 5 an hour per IP).
    $t0 = getenv('MATHEEL_DEVICE_TOKEN') ?: $live->onboard(['environment' => 'sandbox', 'seller' => [
        'vat_number' => '399999999900003', 'legal_name_ar' => 'شركة توريد التكنولوجيا بأقصى سرعة المحدودة',
        'legal_name_en' => 'Maximum Speed Tech Supply LTD', 'crn' => '1010010000',
        'address' => ['street' => 'Prince Sultan', 'building_number' => '2322', 'district' => 'Al-Murabba', 'city' => 'Riyadh', 'postal_code' => '23333'],
    ]])['device_token'];
    $lpdo = connect();
    $lpdo->exec('DROP TABLE IF EXISTS matheel_invoices');
    $lpdo->exec('DROP TABLE IF EXISTS matheel_device');
    $lq = new PdoQueue($lpdo, $live, $dir . '/live');
    $lq->install();
    $lq->setToken($t0);
    $paid = $lq->invoice('live-1', ['type' => 'simplified', 'prices_include_vat' => true, 'line_items' => [
        ['name' => 'تجديد إقامة', 'quantity' => 1, 'amount' => '150.00'], ['name' => 'رسوم خدمة', 'quantity' => 1, 'amount' => '23.00']]]);
    ok($paid['status'] === 'reported', 'live: ZATCA reported the invoice (' . $paid['status'] . ' ' . ($paid['error'] ?? '') . ')');
    ok($paid['pdf_path'] !== null && substr((string) file_get_contents($paid['pdf_path']), 0, 5) === '%PDF-', 'live: the PDF is on disk');
    ok(strpos((string) $paid['invoice_xml'], $paid['uuid']) !== false, 'live: the legal XML in the database carries the row uuid');
    $dup = $lq->invoice('live-1', ['type' => 'simplified', 'line_items' => []]);
    ok($dup['id'] === $paid['id'], 'live: duplicate notification returns the same invoice');

    // The answer to live-1 "was lost": the same request with the old token is replayed, never filed twice.
    $replay = $live->fileInvoice(['deviceToken' => $t0, 'pdf' => true, 'invoice' => ['type' => 'simplified', 'uuid' => $paid['uuid'],
        'number' => $paid['number'], 'prices_include_vat' => true, 'line_items' => [['name' => 'تجديد إقامة', 'quantity' => 1, 'amount' => '150.00']]]]);
    ok(($replay['replayed'] ?? false) === true && $replay['zatca_uuid'] === $paid['zatca_uuid'], 'live: a resend of an accepted document is replayed (same zatca_uuid)');

    // Another sale on this device with the old token: 409 latest_device_token, stored, filed as the next counter.
    $held = new Held($t0);
    $next = $live->fileWithStore($held, ['type' => 'simplified', 'number' => 'EXT-1', 'line_items' => [['name' => 'Fee', 'quantity' => 1, 'price' => '10.00']]], ['uuid' => Matheel::uuid4()]);
    ok($next['status'] === 'reported' && (int) $next['counter'] === (int) $replay['counter'] + 1 && $held->token === $next['device_token'], 'live: a stale token learns the latest one and files as the next counter');

    // The queue's own token is now stale too: the refund recovers the same way.
    $ref = $lq->refund('live-refund-1', 'live-1', [['name' => 'رسوم خدمة', 'quantity' => 1, 'amount' => '23.00']], 'استرجاع جزئي');
    ok($ref['status'] === 'reported', 'live: ZATCA accepted the partial-refund credit note after a stale token (' . $ref['status'] . ' ' . ($ref['error'] ?? '') . ')');
}

echo $failed === 0 ? "ALL PASSED\n" : "$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
