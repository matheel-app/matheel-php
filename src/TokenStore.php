<?php
// The interface a caller implements to hold one device's token under its own database lock.

declare(strict_types=1);

namespace Matheel;

/** Where the caller keeps one device's token, read and written inside one DB transaction (SELECT … FOR UPDATE). */
interface TokenStore
{
    /** Returns the current device token, locking its row. */
    public function read(): string;

    /** Saves the token returned by an accepted invoice. */
    public function write(string $token): void;
}
