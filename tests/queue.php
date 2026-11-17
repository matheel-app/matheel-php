<?php
// PdoQueue checks on SQLite: offline with a scripted API (duplicates, outage, refunds, rejection), then live on the sandbox.

declare(strict_types=1);

spl_autoload_register(function ($class) {
    if (strpos($class, 'Matheel\\') === 0) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
    }
});

use Matheel\Matheel;
use Matheel\PdoQueue;

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

/** A client whose answers are scripted: each call pops the next [status, body]. */
final class Scripted extends Matheel
{
    public $calls = [];
    public $answers = [];

    protected function send(string $path, array $body): array
    {
        $this->calls[] = $body;
        return array_shift($this->answers) ?? [503, ['error' => 'zatca_unavailable']];
    }
}

function accepted(string $token, string $status = 'reported'): array
{
    return [200, ['status' => $status, 'device_token' => $token, 'zatca_uuid' => 'u-' . $token, 'qr' => 'QR',
        'invoice_xml' => base64_encode('<Invoice/>'), 'pdf' => base64_encode('%PDF-1.7')]];
}

$dir = sys_get_temp_dir() . '/matheel-queue-' . getmypid();
$api = new Scripted('k');
$q = new PdoQueue(new PDO('sqlite::memory:'), $api, $dir);
$q->install();
$q->setToken('t0');
$lines = [['name' => 'Coffee', 'quantity' => 2, 'price' => '10.00'], ['name' => 'Cake', 'quantity' => 1, 'price' => '20.00']];

$api->answers = [accepted('t1')];
$row = $q->invoice('telr-1', ['type' => 'simplified', 'line_items' => $lines]);
ok($row['status'] === 'reported' && $row['number'] === 'INV-1', 'a payment is filed at once and numbered');
ok($api->calls[0]['device_token'] === 't0' && $api->calls[0]['pdf'] === true, 'it used the stored token and asked for the PDF');
ok(is_file($row['xml_path']) && is_file($row['pdf_path']), 'XML and PDF are archived on disk');

$again = $q->invoice('telr-1', ['type' => 'simplified', 'line_items' => $lines]);
ok(count($api->calls) === 1 && $again['id'] === $row['id'], 'the same payment notified twice makes no second invoice');

$api->answers = [[503, ['error' => 'zatca_unavailable']]];
$down = $q->invoice('telr-2', ['type' => 'simplified', 'line_items' => $lines]);
ok($down['status'] === 'queued' && (int) $down['attempts'] === 1, 'ZATCA down: the invoice waits in the queue');
$api->answers = [accepted('t2')];
$q->process();
ok($q->get('telr-2')['status'] === 'reported' && $api->calls[2]['device_token'] === 't1', 'cron files it later with the newest token');

$api->answers = [accepted('t3')];
$refund = $q->refund('refund-1', 'telr-1', [['name' => 'Cake', 'quantity' => 1, 'price' => '20.00']], 'Returned');
$sent = end($api->calls)['invoice'];
ok($refund['status'] === 'reported' && $sent['document_type'] === 'credit_note', 'a partial refund files a credit note');
ok($sent['original_invoice_number'] === 'INV-1' && count($sent['line_items']) === 1, 'linked to the original invoice, refunded lines only');

$api->answers = [[422, ['error' => 'zatca_rejected', 'errors' => [['code' => 'BR-KSA-08']]]]];
$bad = $q->invoice('telr-3', ['type' => 'simplified', 'line_items' => $lines]);
ok($bad['status'] === 'rejected' && strpos($bad['error'], 'BR-KSA-08') !== false, 'a ZATCA rejection is marked, not retried');

// Live: a device on ZATCA's developer sandbox (keyless), then a real invoice, a duplicate and a partial refund.
if (getenv('MATHEEL_OFFLINE') !== '1') {
    $live = new Matheel();
    $device = $live->onboard(['environment' => 'sandbox', 'seller' => [
        'vat_number' => '399999999900003', 'legal_name_ar' => 'شركة توريد التكنولوجيا بأقصى سرعة المحدودة',
        'legal_name_en' => 'Maximum Speed Tech Supply LTD', 'crn' => '1010010000',
        'address' => ['street' => 'Prince Sultan', 'building_number' => '2322', 'district' => 'Al-Murabba', 'city' => 'Riyadh', 'postal_code' => '23333'],
    ]]);
    $lq = new PdoQueue(new PDO('sqlite::memory:'), $live, $dir . '-live');
    $lq->install();
    $lq->setToken($device['device_token']);
    $paid = $lq->invoice('live-1', ['type' => 'simplified', 'prices_include_vat' => true, 'line_items' => [
        ['name' => 'تجديد إقامة', 'quantity' => 1, 'amount' => '150.00'], ['name' => 'رسوم خدمة', 'quantity' => 1, 'amount' => '23.00']]]);
    ok($paid['status'] === 'reported', 'live: ZATCA reported the invoice (' . $paid['status'] . ' ' . ($paid['error'] ?? '') . ')');
    ok(substr((string) file_get_contents((string) $paid['pdf_path']), 0, 5) === '%PDF-', 'live: the PDF is on disk');
    $dup = $lq->invoice('live-1', ['type' => 'simplified', 'line_items' => []]);
    ok($dup['id'] === $paid['id'], 'live: duplicate notification returns the same invoice');
    $ref = $lq->refund('live-refund-1', 'live-1', [['name' => 'رسوم خدمة', 'quantity' => 1, 'amount' => '23.00']], 'استرجاع جزئي');
    ok($ref['status'] === 'reported', 'live: ZATCA accepted the partial-refund credit note (' . $ref['status'] . ' ' . ($ref['error'] ?? '') . ')');
}

echo $failed === 0 ? "ALL PASSED\n" : "$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
