<?php
// The exception for an API answer other than 200.

declare(strict_types=1);

namespace Matheel;

/** An API answer other than 200: carries the HTTP status (0 for a network failure), the parsed body and the error code. */
class MatheelError extends \RuntimeException
{
    /** @var int */
    public $status;
    /** @var mixed */
    public $body;
    /** @var string */
    public $errorCode;

    /** Builds the error from the HTTP status and the parsed response body. @param mixed $body */
    public function __construct(int $status, $body)
    {
        $this->status = $status;
        $this->body = $body;
        $this->errorCode = self::codeOf($status, $body);
        parent::__construct("Matheel {$status}: {$this->errorCode}", $status);
    }

    /** The error code: `error` (a string or `{code}`), else `zatca_rejected` when ZATCA's `errors` are present. @param mixed $body */
    public static function codeOf(int $status, $body): string
    {
        $error = is_array($body) ? ($body['error'] ?? null) : null;
        if (is_string($error)) {
            return $error;
        }
        if (is_array($error) && isset($error['code']) && is_string($error['code'])) {
            return $error['code'];
        }
        if (is_array($body) && !empty($body['errors'])) {
            return 'zatca_rejected';
        }
        return 'http_' . $status;
    }
}
