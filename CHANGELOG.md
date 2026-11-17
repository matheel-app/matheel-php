## 0.1.2

* Connects with a classic X25519 TLS key share: some networks between cloud hosts and Matheel's edge drop the large post-quantum ClientHello that OpenSSL 3.5 sends by default (Node 24, PHP and Python failed from GitHub's network; X25519 succeeded).

## 0.1.1

* No deprecation notice on PHP 8.5: dropped `curl_close`, which does nothing since PHP 8.0.
* The keyless sandbox now needs only `line_items` (counter, uuid and number default), so the README quick start runs as written.

## 0.1.0

* First release: `fileInvoice`, `fileWithStore`, `onboard`, `activationLink`, `decodeQr`, `verifyActivationCallback`, `verifyTelr`.
