<?php
// A database-backed invoice queue: one row per payment or refund, filed in order under a device lock, retried by cron.

declare(strict_types=1);

namespace Matheel;

use PDO;

/**
 * Files one invoice per payment and one credit note per refund, exactly once, in order, surviving outages.
 *
 *   $q = new PdoQueue($pdo, new Matheel(getenv('MATHEEL_KEY')), '/var/invoices');
 *   $q->install();                              // once: creates matheel_device and matheel_invoices
 *   $q->setToken($deviceToken);                 // once: the token from onboarding
 *   $row = $q->invoice($telrTranRef, ['type' => 'simplified', 'line_items' => $items, 'prices_include_vat' => true]);
 *   $q->refund($refundRef, $telrTranRef, $refundedItems, 'Customer refund');
 *   // cron, every 5 minutes: $q->process();
 *
 * A payment reference seen before returns its existing row (a gateway that notifies twice never makes two invoices).
 * Network errors, 409, 429 and 5xx leave the row queued for the next process(); a ZATCA rejection marks it rejected.
 */
final class PdoQueue
{
    private const RETRY = [0, 409, 429, 500, 502, 503, 504];

    /** @var PDO */
    private $db;
    /** @var Matheel */
    private $client;
    /** @var string */
    private $archive;
    /** @var int */
    private $device;

    public function __construct(PDO $db, Matheel $client, string $archiveDir, int $deviceId = 1)
    {
        $this->db = $db;
        $this->client = $client;
        $this->archive = rtrim($archiveDir, '/');
        $this->device = $deviceId;
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** Creates the two tables if missing (MySQL, PostgreSQL or SQLite). */
    public function install(): void
    {
        $id = [
            'mysql' => 'BIGINT AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        ][$this->driver()] ?? 'BIGINT AUTO_INCREMENT PRIMARY KEY';
        $this->db->exec('CREATE TABLE IF NOT EXISTS matheel_device (id INT PRIMARY KEY, token TEXT NOT NULL)');
        $this->db->exec("CREATE TABLE IF NOT EXISTS matheel_invoices (
            id $id, ref VARCHAR(191) NOT NULL UNIQUE, kind VARCHAR(16) NOT NULL, original_ref VARCHAR(191),
            payload TEXT NOT NULL, status VARCHAR(16) NOT NULL, attempts INT NOT NULL DEFAULT 0, error TEXT,
            number VARCHAR(64), zatca_uuid VARCHAR(64), qr TEXT, xml_path TEXT, pdf_path TEXT,
            created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL)");
    }

    /** Saves the device token from onboarding (or a renewal). */
    public function setToken(string $token): void
    {
        $this->db->prepare('DELETE FROM matheel_device WHERE id = ?')->execute([$this->device]);
        $this->db->prepare('INSERT INTO matheel_device (id, token) VALUES (?, ?)')->execute([$this->device, $token]);
    }

    /** Queues the invoice for a paid payment (once per `$paymentRef`) and tries to file it now; returns its row. */
    public function invoice(string $paymentRef, array $invoice): array
    {
        return $this->enqueue($paymentRef, 'invoice', null, $invoice);
    }

    /**
     * Queues a credit note for a full or partial refund of the invoice filed for `$paymentRef` (once per `$refundRef`).
     * `$lineItems` are the refunded lines only, priced like the original invoice.
     */
    public function refund(string $refundRef, string $paymentRef, array $lineItems, string $reason): array
    {
        $original = $this->get($paymentRef);
        if ($original === null) {
            throw new \InvalidArgumentException("No invoice for payment $paymentRef");
        }
        $invoice = json_decode($original['payload'], true);
        $invoice['line_items'] = $lineItems;
        $invoice['document_type'] = 'credit_note';
        $invoice['note'] = $reason;
        return $this->enqueue($refundRef, 'credit_note', $paymentRef, $invoice);
    }

    /** Files queued rows oldest first; stops at the first outage. Returns ['filed' => n, 'rejected' => n, 'waiting' => n]. */
    public function process(int $limit = 50): array
    {
        $done = ['filed' => 0, 'rejected' => 0, 'waiting' => 0];
        for ($i = 0; $i < $limit; $i++) {
            $outcome = $this->fileNext();
            if ($outcome === null) {
                break;
            }
            $done[$outcome]++;
            if ($outcome === 'waiting') {
                break; // ZATCA or Matheel is down: keep the order, try again on the next run
            }
        }
        return $done;
    }

    /** The row for a payment or refund reference, or null. */
    public function get(string $ref): ?array
    {
        $st = $this->db->prepare('SELECT * FROM matheel_invoices WHERE ref = ?');
        $st->execute([$ref]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function enqueue(string $ref, string $kind, ?string $originalRef, array $invoice): array
    {
        $now = gmdate('c');
        try {
            $this->db->prepare(
                'INSERT INTO matheel_invoices (ref, kind, original_ref, payload, status, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$ref, $kind, $originalRef, json_encode($invoice, JSON_UNESCAPED_UNICODE), 'queued', $now, $now]);
        } catch (\PDOException $e) {
            $existing = $this->get($ref); // the gateway notified twice: the first row is the answer
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }
        $this->process(10);
        return $this->get($ref);
    }

    /** Files the oldest queued row under the device lock; null when nothing is queued. */
    private function fileNext(): ?string
    {
        $this->lock();
        try {
            $row = $this->db->query(
                "SELECT * FROM matheel_invoices WHERE status = 'queued' ORDER BY id LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $this->end('COMMIT');
                return null;
            }
            $outcome = $this->file($row);
            $this->end('COMMIT'); // the new token and the row's status are saved together
            return $outcome;
        } catch (\Throwable $e) {
            $this->end('ROLLBACK');
            throw $e;
        }
    }

    private function file(array $row): string
    {
        $invoice = json_decode($row['payload'], true);
        $invoice['number'] = $invoice['number'] ?? (($row['kind'] === 'credit_note' ? 'CRN-' : 'INV-') . $row['id']);
        if ($row['kind'] === 'credit_note') {
            $original = $this->get($row['original_ref']);
            if ($original === null || !in_array($original['status'], ['reported', 'cleared'], true)) {
                return $this->mark($row, 'queued', 'waiting', 'original invoice not accepted yet');
            }
            $invoice['original_invoice_number'] = $original['number'];
        }
        try {
            $res = $this->client->fileWithStore($this->store(), $invoice, ['pdf' => true]);
        } catch (MatheelError $e) {
            if (in_array($e->status, self::RETRY, true)) {
                return $this->mark($row, 'queued', 'waiting', $e->getMessage());
            }
            return $this->mark($row, 'rejected', 'rejected', json_encode($e->body, JSON_UNESCAPED_UNICODE));
        } catch (\RuntimeException $e) {
            return $this->mark($row, 'queued', 'waiting', $e->getMessage()); // network
        }
        [$xml, $pdf] = $this->archive($invoice['number'], $res);
        $this->db->prepare(
            'UPDATE matheel_invoices SET status = ?, number = ?, zatca_uuid = ?, qr = ?, xml_path = ?, pdf_path = ?,
             attempts = attempts + 1, error = NULL, updated_at = ? WHERE id = ?'
        )->execute([$res['status'], $invoice['number'], $res['zatca_uuid'] ?? null, $res['qr'] ?? null, $xml, $pdf, gmdate('c'), $row['id']]);
        return 'filed';
    }

    private function mark(array $row, string $status, string $outcome, string $error): string
    {
        $this->db->prepare('UPDATE matheel_invoices SET status = ?, attempts = attempts + 1, error = ?, updated_at = ? WHERE id = ?')
            ->execute([$status, substr($error, 0, 2000), gmdate('c'), $row['id']]);
        return $outcome;
    }

    /** Writes the legal XML and the PDF under archive/YYYY/MM/; the database keeps their paths. */
    private function archive(string $number, array $res): array
    {
        $dir = $this->archive . '/' . gmdate('Y/m');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        $base = $dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $number);
        file_put_contents($base . '.xml', base64_decode($res['invoice_xml'] ?? ''));
        $pdf = null;
        if (!empty($res['pdf'])) {
            file_put_contents($base . '.pdf', base64_decode($res['pdf']));
            $pdf = $base . '.pdf';
        }
        return [$base . '.xml', $pdf];
    }

    /** Opens a transaction that holds the device row: two workers file one after the other. */
    private function lock(): void
    {
        if ($this->driver() === 'sqlite') {
            $this->db->exec('BEGIN IMMEDIATE'); // SQLite has no row locks; this takes the write lock
            return;
        }
        $this->db->beginTransaction();
        $this->db->prepare('SELECT token FROM matheel_device WHERE id = ? FOR UPDATE')->execute([$this->device]);
    }

    /** Commits or rolls back the transaction lock() opened (SQLite's was opened by hand, so it is closed by hand). */
    private function end(string $how): void
    {
        if ($this->driver() === 'sqlite') {
            $this->db->exec($how);
        } elseif ($this->db->inTransaction()) {
            $how === 'COMMIT' ? $this->db->commit() : $this->db->rollBack();
        }
    }

    private function store(): TokenStore
    {
        $db = $this->db;
        $id = $this->device;
        return new class ($db, $id) implements TokenStore {
            /** @var PDO */
            private $db;
            /** @var int */
            private $id;

            public function __construct(PDO $db, int $id)
            {
                $this->db = $db;
                $this->id = $id;
            }

            public function read(): string
            {
                $st = $this->db->prepare('SELECT token FROM matheel_device WHERE id = ?');
                $st->execute([$this->id]);
                return (string) $st->fetchColumn();
            }

            public function write(string $token): void
            {
                $this->db->prepare('UPDATE matheel_device SET token = ? WHERE id = ?')->execute([$token, $this->id]);
            }
        };
    }

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
