<?php
// The interface a caller implements to hold one device's token under its own database lock.

declare(strict_types=1);

namespace Matheel;

/** Where the caller keeps one device's token, read and written inside one DB transaction (SELECT … FOR UPDATE). */
interface TokenStore
{
    /** Returns the current device token, locking its row. */
    public function read(): string;

    /** Saves the token of an accepted invoice, or the `latest_device_token` a 409 sent back for a stale one. */
    public function write(string $token): void;
}
