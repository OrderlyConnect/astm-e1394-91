<?php

declare(strict_types=1);

namespace Astm\Transport;

use Astm\Exceptions\AstmException;
use Astm\Exceptions\ConnectionException;

/**
 * Wraps an already-open PHP stream resource (e.g. an accepted TCP client socket)
 * so it can be used anywhere a {@see TransportInterface} is expected.
 *
 * Used internally by {@see \Astm\TcpServer} for per-connection handling, but
 * also useful when integrating with custom socket or pipe resources.
 *
 * Usage:
 *
 *   $socket    = stream_socket_client('tcp://192.168.1.50:3001');
 *   $transport = StreamTransport::wrap($socket);
 *   $receiver  = new \Astm\Receiver($transport);
 */
final class StreamTransport implements TransportInterface
{
    private bool $ownsStream;

    /**
     * @param resource $stream      An open, readable/writable PHP stream.
     * @param bool     $ownsStream  If true, fclose() is called on disconnect().
     * @param int      $readTimeout Seconds to wait for a byte.
     */
    public function __construct(
        private mixed $stream,
        bool          $ownsStream  = true,
        private int   $readTimeout = 30,
    ) {
        if (!is_resource($stream)) {
            throw new AstmException('StreamTransport: argument must be a PHP stream resource.');
        }
        $this->ownsStream = $ownsStream;
    }

    /** Named constructor — reads naturally at call sites. */
    public static function wrap(mixed $stream, bool $ownsStream = true, int $readTimeout = 30): static
    {
        return new static($stream, $ownsStream, $readTimeout);
    }

    // -----------------------------------------------------------------------
    //  TransportInterface
    // -----------------------------------------------------------------------

    /** No-op — stream is already open. */
    public function connect(): void {}

    public function disconnect(): void
    {
        if ($this->ownsStream && is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function write(string $data): void
    {
        $this->assertOpen();
        $total  = strlen($data);
        $offset = 0;

        while ($offset < $total) {
            $written = @fwrite($this->stream, substr($data, $offset));
            if ($written === false) {
                throw new ConnectionException('StreamTransport: write failed.');
            }
            $offset += $written;
        }
    }

    public function read(int $length = 1024): string
    {
        if (!$this->isConnected()) {
            return '';
        }
        $data = @fread($this->stream, $length);
        return ($data === false) ? '' : $data;
    }

    public function readByte(): string
    {
        $this->assertOpen();
        $deadline = microtime(true) + $this->readTimeout;

        while (microtime(true) < $deadline) {
            $byte = @fread($this->stream, 1);
            if ($byte !== false && $byte !== '') {
                return $byte;
            }
            usleep(1_000);
        }

        throw new ConnectionException('StreamTransport: read timeout.');
    }

    public function isConnected(): bool
    {
        return is_resource($this->stream) && !feof($this->stream);
    }

    // -----------------------------------------------------------------------

    private function assertOpen(): void
    {
        if (!$this->isConnected()) {
            throw new ConnectionException('StreamTransport: stream is closed or at EOF.');
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
