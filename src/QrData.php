<?php
// The decoded fields of a ZATCA Phase 2 TLV QR.

declare(strict_types=1);

namespace Matheel;

/** The fields of a ZATCA Phase 2 QR; tags 8 and 9 are base64. */
final class QrData
{
    /** @var string Tag 1: the seller's name. */
    public $sellerName;
    /** @var string Tag 2: the seller's VAT number. */
    public $vatNumber;
    /** @var string Tag 3: the issue timestamp. */
    public $timestamp;
    /** @var string Tag 4: the total with VAT. */
    public $total;
    /** @var string Tag 5: the VAT amount. */
    public $vatAmount;
    /** @var string|null Tag 6: the invoice hash (base64). */
    public $invoiceHash;
    /** @var string|null Tag 7: the ECDSA signature (base64). */
    public $signature;
    /** @var string|null Tag 8: the public key, base64. */
    public $publicKey;
    /** @var string|null Tag 9: ZATCA's certificate signature, base64. */
    public $certificateSignature;

    /** Builds the value from decoded tags (tag number => raw bytes). @param array<int, string> $tags */
    public function __construct(array $tags)
    {
        $this->sellerName = $tags[1] ?? '';
        $this->vatNumber = $tags[2] ?? '';
        $this->timestamp = $tags[3] ?? '';
        $this->total = $tags[4] ?? '';
        $this->vatAmount = $tags[5] ?? '';
        $this->invoiceHash = $tags[6] ?? null;
        $this->signature = $tags[7] ?? null;
        $this->publicKey = isset($tags[8]) ? base64_encode($tags[8]) : null;
        $this->certificateSignature = isset($tags[9]) ? base64_encode($tags[9]) : null;
    }
}
