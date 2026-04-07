<?php

declare(strict_types=1);

namespace Astm\Transport;

use Astm\Exceptions\AstmException;

/**
 * Common interface for all ASTM transport implementations.
 *
 * A transport is a bidirectional byte pipe.  It knows nothing about the
 * ASTM LLP protocol — it just sends and receives raw bytes.
 */
interface TransportInterface
{
    /**
     * Open the connection.  Implementations should be idempotent (calling
     * connect() on an already-connected transport is a no-op).
     *
     * @throws AstmException on connection failure.
     */
    public function connect(): void;

    /**
     * Close the connection.
     */
    public function disconnect(): void;

    /**
     * Write raw bytes to the transport.
     *
     * @throws AstmException on write failure.
     */
    public function write(string $data): void;

    /**
     * Read up to $length bytes.  Returns an empty string on timeout or when
     * no data is available.
     *
     * @throws AstmException on read error.
     */
    public function read(int $length = 1024): string;

    /**
     * Read exactly one byte, blocking until available or timeout.
     */
    public function readByte(): string;

    /**
     * Returns true while the connection is open.
     */
    public function isConnected(): bool;
}
