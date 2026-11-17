<?php
// A database-backed invoice queue: one row per payment or refund, filed in order under a device lock, retried by cron.

declare(strict_types=1);

namespace Matheel;

use PDO;

/**
 * Files one invoice per payment and one credit note per refund, exactly once, in order, surviving outages.
 *
 *   $q = new PdoQueue($pdo, new Matheel(getenv('MATHEEL_KEY')), '/var/invoices');
 *   $q->install();                              // once, and after each upgrade: creates or migrates the tables
 *   $q->setToken($deviceToken);                 // once: the token from onboarding
 *   $row = $q->invoice($telrTranRef, ['type' => 'simplified', 'line_items' => $items, 'prices_include_vat' => true]);
 *   $q->refund($refundRef, $telrTranRef, $refundedItems, 'Customer refund');
 *   // cron, every 5 minutes: $q->process(); and alert on $q->attention()
 *
 * Statuses: queued (filed later, same uuid), reported / cleared (409 already_filed counts too), rejected (the document is wrong: fix and enqueue it
 * again under a new reference) and attention (a bad key, CSID or plan: still retried, but a person must act).
 * A payment reference seen before returns its existing row (a gateway that notifies twice never makes two invoices).
 */
final class PdoQueue
{
    /** Pending rows: retried by process(). */
    private const PENDING = "('queued', 'attention')";

    /** @var PDO */
    private $db;
    /** @var Matheel */
    private $client;
    /** @var string */
    private $archive;
    /** @var int */
    private $device;

    /** Wraps `$db` (errors become exceptions); XML and PDF copies go under `$archiveDir`; `$deviceId` picks the token row. */
    public function __construct(PDO $db, Matheel $client, string $archiveDir, int $deviceId = 1)
    {
        $this->db = $db;
        $this->client = $client;
        $this->archive = rtrim($archiveDir, '/');
        $this->device = $deviceId;
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /** Creates the two tables if missing and migrates a 0.2.0 table (MySQL, PostgreSQL or SQLite). Safe to run again. */
    public function install(): void
    {
        $driver = $this->driver();
        $id = ['pgsql' => 'BIGSERIAL PRIMARY KEY', 'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT'][$driver] ?? 'BIGINT AUTO_INCREMENT PRIMARY KEY';
        $xml = $driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';
        $this->db->exec('CREATE TABLE IF NOT EXISTS matheel_device (id INT PRIMARY KEY, token TEXT NOT NULL)');
        $this->db->exec("CREATE TABLE IF NOT EXISTS matheel_invoices (
            id $id, ref VARCHAR(191) NOT NULL UNIQUE, kind VARCHAR(16) NOT NULL, original_ref VARCHAR(191),
            payload TEXT NOT NULL, status VARCHAR(16) NOT NULL, attempts INT NOT NULL DEFAULT 0, error TEXT,
            number VARCHAR(64), zatca_uuid VARCHAR(64), qr TEXT, xml_path TEXT, pdf_path TEXT,
            created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, uuid VARCHAR(36), invoice_xml $xml)");
        $columns = [];
        $st = $this->db->query('SELECT * FROM matheel_invoices WHERE 1 = 0');
        for ($i = 0; $i < $st->columnCount(); $i++) {
            $columns[] = strtolower($st->getColumnMeta($i)['name']);
        }
        foreach (['uuid' => 'VARCHAR(36)', 'invoice_xml' => $xml] as $name => $type) {
            if (!in_array($name, $columns, true)) {
                $this->db->exec("ALTER TABLE matheel_invoices ADD COLUMN $name $type"); // a 0.2.0 table
            }
        }
        // process() and attention() select by status in id order: index it so cron never scans the whole history.
        if ($driver === 'mysql') {
            if (!$this->db->query("SHOW INDEX FROM matheel_invoices WHERE Key_name = 'matheel_invoices_status'")->fetch()) {
                $this->db->exec('CREATE INDEX matheel_invoices_status ON matheel_invoices (status, id)');
            }
        } else {
            $this->db->exec('CREATE INDEX IF NOT EXISTS matheel_invoices_status ON matheel_invoices (status, id)');
        }
        // 0.2.0 rows still pending get the uuid they will keep on every attempt from now on.
        $missing = $this->db->query('SELECT id FROM matheel_invoices WHERE uuid IS NULL AND status IN ' . self::PENDING)->fetchAll(PDO::FETCH_COLUMN);
        $set = $this->db->prepare('UPDATE matheel_invoices SET uuid = ? WHERE id = ?');
        foreach ($missing as $rowId) {
            $set->execute([Matheel::uuid4(), $rowId]);
        }
    }

    /** Saves the device token from onboarding (or a renewal), under the same lock as filing. */
    public function setToken(string $token): void
    {
        $this->lock();
        try {
            $st = $this->db->prepare('SELECT 1 FROM matheel_device WHERE id = ?');
            $st->execute([$this->device]);
            $sql = $st->fetchColumn() ? 'UPDATE matheel_device SET token = ? WHERE id = ?' : 'INSERT INTO matheel_device (token, id) VALUES (?, ?)';
            $this->db->prepare($sql)->execute([$token, $this->device]);
            $this->end('COMMIT');
        } catch (\Throwable $e) {
            $this->end('ROLLBACK');
            throw $e;
        }
    }

    /** Queues the invoice for a paid payment (once per `$paymentRef`) and tries to file it now; returns its row. */
    public function invoice(string $paymentRef, array $invoice): array
    {
        return $this->enqueue($paymentRef, 'invoice', null, $invoice);
    }

    /**
     * Queues a credit note for a full or partial refund of the invoice filed for `$paymentRef` (once per `$refundRef`).
     * `$lineItems` are the refunded lines only, priced like the original invoice. Refused when the original was rejected.
     */
    public function refund(string $refundRef, string $paymentRef, array $lineItems, string $reason): array
    {
        $original = $this->get($paymentRef);
        if ($original === null) {
            throw new \InvalidArgumentException("No invoice for payment $paymentRef");
        }
        if ($original['status'] === 'rejected') {
            throw new \InvalidArgumentException("The invoice for payment $paymentRef was rejected: there is nothing to refund on it");
        }
        $invoice = json_decode($original['payload'], true);
        // The credit note is its own document: its own number, uuid, issue time, counter and totals.
        unset($invoice['number'], $invoice['uuid'], $invoice['issued_at'], $invoice['subtotal'], $invoice['vat_amount'], $invoice['total'], $invoice['counter']);
        $invoice['line_items'] = $lineItems;
        $invoice['document_type'] = 'credit_note';
        $invoice['note'] = $reason;
        return $this->enqueue($refundRef, 'credit_note', $paymentRef, $invoice);
    }

    /**
     * Files pending rows oldest first. A row waiting on its original, or refused before anything reached ZATCA,
     * does not hold up the rows after it; a filing with no answer (network, 5xx, 409) or a key problem stops the run,
     * because the next row must not take that row's place in the chain. Returns counts of filed, rejected, waiting, attention.
     */
    public function process(int $limit = 50): array
    {
        $done = ['filed' => 0, 'rejected' => 0, 'waiting' => 0, 'attention' => 0];
        $after = 0;
        for ($i = 0; $i < $limit; $i++) {
            $step = $this->fileNext($after, null);
            if ($step === null) {
                break;
            }
            [$after, $outcome, $stop] = $step;
            $done[$outcome]++;
            if ($stop) {
                break;
            }
        }
        return $done;
    }

    /**
     * Rows a person must look at (oldest first, at most `$limit`): status attention (key, CSID or plan problem), rows
     * still queued `$stuckHours` after they were queued (simplified invoices must reach ZATCA within 24 hours), and
     * accepted rows with no saved XML (409 already_filed after a lost answer).
     */
    public function attention(int $stuckHours = 12, int $limit = 100): array
    {
        // ponytail: the already_filed branch filters every accepted row (an index range, then row reads); give it a
        // flag column with its own index if attention() runs often on millions of rows.
        $st = $this->db->prepare(
            "SELECT * FROM matheel_invoices WHERE status = 'attention' OR (status = 'queued' AND created_at < ?)
             OR (status IN ('reported', 'cleared') AND invoice_xml IS NULL AND error IS NOT NULL) ORDER BY id LIMIT " . max(1, $limit)
        );
        $st->execute([gmdate('c', time() - $stuckHours * 3600)]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The row for a payment or refund reference, or null. */
    public function get(string $ref): ?array
    {
        $st = $this->db->prepare('SELECT * FROM matheel_invoices WHERE ref = ?');
        $st->execute([$ref]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Inserts the row with its own uuid (or returns the existing one) and files this row only, if nothing older is pending. */
    private function enqueue(string $ref, string $kind, ?string $originalRef, array $invoice): array
    {
        $this->noOpenTransaction();
        $uuid = isset($invoice['uuid']) && is_string($invoice['uuid']) && $invoice['uuid'] !== '' ? $invoice['uuid'] : Matheel::uuid4();
        $now = gmdate('c');
        try {
            $this->db->prepare(
                'INSERT INTO matheel_invoices (ref, kind, original_ref, payload, status, uuid, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$ref, $kind, $originalRef, json_encode($invoice, JSON_UNESCAPED_UNICODE), 'queued', $uuid, $now, $now]);
        } catch (\PDOException $e) {
            $existing = $this->get($ref); // the gateway notified twice: the first row is the answer
            if ($existing !== null) {
                return $existing;
            }
            throw $e;
        }
        $row = $this->get($ref);
        $this->fileNext(0, (int) $row['id']);
        return $this->get($ref);
    }

    /**
     * Under the device lock, files the first pending row after id `$after` (or only row `$only`, when no older row is
     * pending: that one may be an unanswered filing that must be resent first). Returns [id, outcome, stop] or null.
     */
    private function fileNext(int $after, ?int $only): ?array
    {
        $this->lock();
        try {
            if ($only === null) {
                $st = $this->db->prepare('SELECT * FROM matheel_invoices WHERE status IN ' . self::PENDING . ' AND id > ? ORDER BY id LIMIT 1');
                $st->execute([$after]);
            } else {
                $st = $this->db->prepare(
                    'SELECT * FROM matheel_invoices m WHERE id = ? AND status IN ' . self::PENDING . ' AND NOT EXISTS
                     (SELECT 1 FROM matheel_invoices o WHERE o.status IN ' . self::PENDING . ' AND o.id < m.id)'
                );
                $st->execute([$only]);
            }
            $row = $st->fetch(PDO::FETCH_ASSOC);
            $st->closeCursor();
            if ($row === false) {
                $this->end('COMMIT');
                return null;
            }
            [$outcome, $stop, $res, $number] = $this->file($row);
            $this->end('COMMIT'); // the new token and the row's answer are saved together, or not at all
        } catch (\Throwable $e) {
            $this->end('ROLLBACK');
            throw $e;
        }
        if ($res !== null) {
            $this->archive((int) $row['id'], $number, $res);
        }
        return [(int) $row['id'], $outcome, $stop];
    }

    /** Files one row inside the open transaction; returns [outcome, stop the run, 200 answer or null, number]. */
    private function file(array $row): array
    {
        $invoice = json_decode($row['payload'], true);
        $invoice['number'] = $invoice['number'] ?? (($row['kind'] === 'credit_note' ? 'CRN-' : 'INV-') . $row['id']);
        $invoice['uuid'] = $row['uuid'];
        if ($row['kind'] === 'credit_note') {
            $original = $this->get((string) $row['original_ref']);
            if ($original === null || $original['status'] === 'rejected') {
                return $this->mark($row, 'rejected', false, 'The original invoice (' . $row['original_ref'] . ') was rejected or is missing: no credit note can reference it');
            }
            if (!in_array($original['status'], ['reported', 'cleared'], true)) {
                return $this->mark($row, 'queued', false, 'Waiting for the original invoice to be accepted', false);
            }
            $invoice['original_invoice_number'] = $original['number'];
        }
        if (empty($row['uuid'])) {
            return $this->mark($row, 'attention', true, 'This row has no uuid: run install() after upgrading', false);
        }
        try {
            $res = $this->client->fileWithStore($this->store(), $invoice, ['pdf' => true]);
        } catch (MatheelError $e) { // only an API answer: a PDOException rolls the whole transaction back
            $kind = Matheel::classify($e);
            $error = $e->getMessage() . ' ' . json_encode($e->body, JSON_UNESCAPED_UNICODE);
            if ($kind === 'already_filed') {
                return $this->alreadyFiled($row, $invoice, $e->body['error'] ?? []);
            }
            if ($kind === 'permanent') {
                return $this->mark($row, 'rejected', false, $error);
            }
            if ($kind === 'needs_attention') {
                return $this->mark($row, 'attention', true, $error);
            }
            // ZATCA unreachable before sending: nothing was filed, the next row may go first. Anything else may have
            // reached ZATCA, so this exact request is resent first on the next run.
            return $this->mark($row, 'queued', !in_array($e->errorCode, ['zatca_unavailable', 'billing_unavailable'], true), $error);
        }
        $xml = base64_decode((string) ($res['invoice_xml'] ?? ''), true);
        $this->db->prepare(
            'UPDATE matheel_invoices SET status = ?, number = ?, zatca_uuid = ?, qr = ?, invoice_xml = ?,
             attempts = attempts + 1, error = NULL, updated_at = ? WHERE id = ?'
        )->execute([$res['status'], $invoice['number'], $res['zatca_uuid'] ?? null, $res['qr'] ?? null, $xml === false ? null : $xml, gmdate('c'), $row['id']]);
        return ['filed', false, $res, $invoice['number']];
    }

    /**
     * ZATCA accepted this uuid earlier and its answer was lost: the row is filed (never resent). With no saved XML the
     * error says so, and attention() lists the row until a person files the copy.
     */
    private function alreadyFiled(array $row, array $invoice, array $error): array
    {
        $status = ($invoice['type'] ?? '') === 'standard' ? 'cleared' : 'reported';
        $note = empty($row['invoice_xml'])
            ? 'already_filed: ZATCA accepted this document earlier (counter ' . ($error['counter'] ?? '?') . '); its answer was lost, so no XML or PDF was saved'
            : null;
        $this->db->prepare(
            'UPDATE matheel_invoices SET status = ?, number = ?, zatca_uuid = ?, attempts = attempts + 1, error = ?, updated_at = ? WHERE id = ?'
        )->execute([$status, $invoice['number'], $error['zatca_uuid'] ?? $row['zatca_uuid'], $note, gmdate('c'), $row['id']]);
        return ['filed', false, null, null];
    }

    /** Saves a row's new status and error; returns [outcome, stop, null, null] for file(). */
    private function mark(array $row, string $status, bool $stop, string $error, bool $attempted = true): array
    {
        $this->db->prepare('UPDATE matheel_invoices SET status = ?, attempts = attempts + ?, error = ?, updated_at = ? WHERE id = ?')
            ->execute([$status, $attempted ? 1 : 0, substr($error, 0, 2000), gmdate('c'), $row['id']]);
        return [$status === 'queued' ? 'waiting' : $status, $stop, null, null];
    }

    /** After commit, best effort: writes the XML and PDF under archive/YYYY/MM/ and records the paths that were written. */
    private function archive(int $id, string $number, array $res): void
    {
        $dir = $this->archive . '/' . gmdate('Y/m');
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return; // the XML is in the database; the PDF can be fetched again by resending (replayed)
        }
        $base = $dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $number);
        $paths = [];
        foreach (['xml' => $res['invoice_xml'] ?? null, 'pdf' => $res['pdf'] ?? null] as $ext => $b64) {
            $bytes = is_string($b64) ? base64_decode($b64, true) : false;
            $paths[$ext] = $bytes !== false && $bytes !== '' && @file_put_contents("$base.$ext", $bytes) === strlen($bytes) ? "$base.$ext" : null;
        }
        $this->db->prepare('UPDATE matheel_invoices SET xml_path = ?, pdf_path = ? WHERE id = ?')->execute([$paths['xml'], $paths['pdf'], $id]);
    }

    /** Opens a transaction that holds the device row: two workers file one after the other. */
    private function lock(): void
    {
        $this->noOpenTransaction();
        if ($this->driver() === 'sqlite') {
            $this->db->exec('BEGIN IMMEDIATE'); // SQLite has no row locks; this takes the write lock
            return;
        }
        $this->db->beginTransaction();
        try {
            $this->db->prepare('SELECT token FROM matheel_device WHERE id = ? FOR UPDATE')->execute([$this->device]);
        } catch (\Throwable $e) {
            $this->db->rollBack(); // a lock timeout must not leave the connection in a dead transaction
            throw $e;
        }
    }

    /** Commits or rolls back the transaction lock() opened (SQLite's was opened by hand, so it is closed by hand). */
    private function end(string $how): void
    {
        if ($this->driver() === 'sqlite') {
            try {
                $this->db->exec($how);
            } catch (\PDOException $e) {
                if ($how === 'COMMIT') {
                    throw $e;
                }
            }
        } elseif ($this->db->inTransaction()) {
            $how === 'COMMIT' ? $this->db->commit() : $this->db->rollBack();
        }
    }

    /** The queue commits its own work: inside a caller's transaction a rollback would undo a filing ZATCA already has. */
    private function noOpenTransaction(): void
    {
        if ($this->db->inTransaction()) {
            throw new \LogicException('PdoQueue needs a connection with no open transaction: call it after commit, or give it its own PDO');
        }
    }

    /** The TokenStore over the device row, read and written inside lock()'s transaction. */
    private function store(): TokenStore
    {
        $db = $this->db;
        $id = $this->device;
        return new class ($db, $id) implements TokenStore {
            /** @var PDO */
            private $db;
            /** @var int */
            private $id;

            /** Binds the store to the connection and device row. */
            public function __construct(PDO $db, int $id)
            {
                $this->db = $db;
                $this->id = $id;
            }

            /** Returns the device token (the row is already locked by lock()). */
            public function read(): string
            {
                $st = $this->db->prepare('SELECT token FROM matheel_device WHERE id = ?');
                $st->execute([$this->id]);
                return (string) $st->fetchColumn();
            }

            /** Saves the token in the open transaction. */
            public function write(string $token): void
            {
                $this->db->prepare('UPDATE matheel_device SET token = ? WHERE id = ?')->execute([$token, $this->id]);
            }
        };
    }

    /** The PDO driver name: mysql, pgsql or sqlite. */
    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
