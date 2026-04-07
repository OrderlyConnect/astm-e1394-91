<?php

declare(strict_types=1);

namespace Astm\Transport;

use Astm\Exceptions\AstmException;
use Astm\Exceptions\ConnectionException;

/**
 * TCP socket transport.
 *
 * Usage:
 *
 *   $transport = new TcpTransport('192.168.1.50', 3001);
 *   $transport->connect();
 *   $transport->write(Ascii::ENQ);
 *   $byte = $transport->readByte();
 *   $transport->disconnect();
 */
final class TcpTransport implements TransportInterface
{
    /** @var resource|false */
    private mixed $socket = false;

    /**
     * @param string $host           Instrument IP address or hostname.
     * @param int    $port           TCP port (3001 is a common default).
     * @param int    $connectTimeout Seconds to wait for connection.
     * @param int    $readTimeout    Seconds to wait for incoming bytes.
     */
    public function __construct(
        private readonly string $host,
        private readonly int    $port            = 3001,
        private readonly int    $connectTimeout  = 10,
        private readonly int    $readTimeout     = 30,
    ) {}

    // -----------------------------------------------------------------------
    //  TransportInterface
    // -----------------------------------------------------------------------

    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        $errorCode    = 0;
        $errorMessage = '';

        $socket = @fsockopen(
            $this->host,
            $this->port,
            $errorCode,
            $errorMessage,
            $this->connectTimeout,
        );

        if ($socket === false) {
            throw new ConnectionException(
                "TcpTransport: cannot connect to {$this->host}:{$this->port} — [{$errorCode}] {$errorMessage}"
            );
        }

        stream_set_timeout($socket, $this->readTimeout);
        stream_set_blocking($socket, false);

        $this->socket = $socket;
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
        $this->socket = false;
    }

    public function write(string $data): void
    {
        $this->assertConnected();

        $total  = strlen($data);
        $offset = 0;

        while ($offset < $total) {
            $written = @fwrite($this->socket, substr($data, $offset));
            if ($written === false) {
                throw new ConnectionException('TcpTransport: write error.');
            }
            $offset += $written;
        }
    }

    public function read(int $length = 1024): string
    {
        $this->assertConnected();
        $data = @fread($this->socket, $length);
        return $data === false ? '' : $data;
    }

    public function readByte(): string
    {
        $this->assertConnected();
        $deadline = microtime(true) + $this->readTimeout;

        while (microtime(true) < $deadline) {
            $byte = @fread($this->socket, 1);
            if ($byte !== false && $byte !== '') {
                return $byte;
            }
            usleep(1_000); // 1 ms busy-wait
        }

        throw new ConnectionException('TcpTransport: read timeout waiting for byte.');
    }

    public function isConnected(): bool
    {
        return is_resource($this->socket) && !feof($this->socket);
    }

    // -----------------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------------

    private function assertConnected(): void
    {
        if (!$this->isConnected()) {
            throw new ConnectionException('TcpTransport: not connected. Call connect() first.');
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
