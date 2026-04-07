<?php

declare(strict_types=1);

namespace Astm\Transport;

use Astm\Exceptions\AstmException;

/**
 * In-memory loopback transport — useful for unit tests and simulation.
 *
 * You can pre-load "incoming" bytes that will be returned by read()/readByte(),
 * and inspect "outgoing" bytes that were written via write().
 *
 *   $transport = new MemoryTransport();
 *   $transport->queueIncoming(Ascii::ACK);         // simulate instrument ACK
 *   $transport->connect();
 *
 *   $sender = new Sender($transport);
 *   $sender->send($message);
 *
 *   echo $transport->getWritten();  // inspect what was put on the wire
 */
final class MemoryTransport implements TransportInterface
{
    private bool   $connected = false;
    private string $incoming  = '';
    private string $outgoing  = '';

    /** Pre-load bytes that will be returned by read()/readByte(). */
    public function queueIncoming(string $data): static
    {
        $this->incoming .= $data;
        return $this;
    }

    /** Return everything that has been written to this transport so far. */
    public function getWritten(): string
    {
        return $this->outgoing;
    }

    /** Clear the outgoing buffer. */
    public function clearWritten(): static
    {
        $this->outgoing = '';
        return $this;
    }

    // -----------------------------------------------------------------------
    //  TransportInterface
    // -----------------------------------------------------------------------

    public function connect(): void
    {
        $this->connected = true;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function write(string $data): void
    {
        $this->assertConnected();
        $this->outgoing .= $data;
    }

    public function read(int $length = 1024): string
    {
        $this->assertConnected();
        $chunk          = substr($this->incoming, 0, $length);
        $this->incoming = substr($this->incoming, $length);
        return $chunk;
    }

    public function readByte(): string
    {
        $this->assertConnected();
        if ($this->incoming === '') {
            throw new AstmException('MemoryTransport: no more incoming bytes.');
        }
        $byte           = $this->incoming[0];
        $this->incoming = substr($this->incoming, 1);
        return $byte;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    private function assertConnected(): void
    {
        if (!$this->connected) {
            throw new AstmException('MemoryTransport: not connected.');
        }
    }
}
