## 0.3.0

* `fileWithStore`: takes a `uuid` option (else generates one, the same for every retry inside the call); on 409
  `device_token_reused` writes `latest_device_token` and resends; on 409 `filing_in_progress` waits Retry-After (at most
  10 s) and retries; a 200 without `device_token` throws `invalid_response` (status 0) instead of passing as success.
* `Matheel::classify($e)`: `retryable`, `permanent`, `needs_attention` or `already_filed`, per the filing contract.
  `Matheel::uuid4()`. `MatheelError::$retryAfter`.
* `PdoQueue`: a uuid per row, sent on every attempt (credit notes get their own); token, status, number, zatca_uuid,
  QR and the legal XML (new `invoice_xml` column) commit in one transaction, disk copies are written after commit and
  never fail a filing; `rejected` only for document errors, new `attention` status for 401/402 (still retried) and
  `attention()` to list rows needing a person (also rows queued over 12 hours and `already_filed` rows with no XML);
  409 `already_filed` marks the row filed and never resends it; an unanswered filing stops the run so its exact
  request is resent first, while a credit note waiting for its original no longer blocks other rows; a credit note on
  a rejected original is refused (`refund()`) or rejected (`process()`); `refund()` drops the original's number, uuid,
  issued_at, totals and counter; `invoice()` files only its own row; `setToken` runs under the filing lock; database
  errors propagate and roll back; a caller's open transaction throws `LogicException`; `install()` migrates 0.2.0
  tables (adds `uuid`, `invoice_xml`, an index on status) and gives pending rows a uuid.

## 0.2.0

* `PdoQueue`: one invoice per payment (duplicate notifications are ignored), credit notes for full and partial refunds,
  retries in order when ZATCA or Matheel is down, XML and PDF archived on disk. MySQL, PostgreSQL, SQLite.

## 0.1.2

* Connects with a classic X25519 TLS key share: some networks between cloud hosts and Matheel's edge drop the large post-quantum ClientHello that OpenSSL 3.5 sends by default (Node 24, PHP and Python failed from GitHub's network; X25519 succeeded).

## 0.1.1

* No deprecation notice on PHP 8.5: dropped `curl_close`, which does nothing since PHP 8.0.
* The keyless sandbox now needs only `line_items` (counter, uuid and number default), so the README quick start runs as written.

## 0.1.0

* First release: `fileInvoice`, `fileWithStore`, `onboard`, `activationLink`, `decodeQr`, `verifyActivationCallback`, `verifyTelr`.
