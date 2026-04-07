<?php

declare(strict_types=1);

namespace Astm\Transport;

use Astm\Exceptions\AstmException;

/**
 * File-based transport for offline / batch ASTM processing.
 *
 * Reads raw ASTM messages from a file (one message per file, or concatenated
 * messages separated by EOT) and writes outbound messages to a file.
 *
 * Useful for:
 *  - Replaying captured instrument traffic during development.
 *  - Integration tests without a live instrument.
 *  - Batch import / export of LLP-framed files saved by a middleware.
 *
 * Usage — read a file:
 *
 *   $transport = FileTransport::forReading('/tmp/capture.astm');
 *   $receiver  = new \Astm\Receiver($transport);
 *   $receiver->listen();
 *
 * Usage — write a file:
 *
 *   $transport = FileTransport::forWriting('/tmp/output.astm');
 *   $sender    = new \Astm\Sender($transport);
 *   $sender->send($message);
 */
final class FileTransport implements TransportInterface
{
    /** @var resource|null */
    private mixed $handle = null;

    private function __construct(
        private readonly string $path,
        private readonly string $mode,
    ) {}

    // -----------------------------------------------------------------------
    //  Named constructors
    // -----------------------------------------------------------------------

    /** Open the file for reading (replay / import). */
    public static function forReading(string $path): static
    {
        return new static($path, 'rb');
    }

    /** Open the file for writing (capture / export). Truncates existing content. */
    public static function forWriting(string $path): static
    {
        return new static($path, 'wb');
    }

    /** Open the file for appending. */
    public static function forAppending(string $path): static
    {
        return new static($path, 'ab');
    }

    // -----------------------------------------------------------------------
    //  TransportInterface
    // -----------------------------------------------------------------------

    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        $handle = @fopen($this->path, $this->mode);
        if ($handle === false) {
            throw new AstmException(
                "FileTransport: cannot open '{$this->path}' (mode={$this->mode})."
            );
        }

        $this->handle = $handle;
    }

    public function disconnect(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
    }

    public function write(string $data): void
    {
        $this->assertConnected();
        if (@fwrite($this->handle, $data) === false) {
            throw new AstmException("FileTransport: write error on '{$this->path}'.");
        }
    }

    public function read(int $length = 1024): string
    {
        $this->assertConnected();
        if (feof($this->handle)) {
            return '';
        }
        $data = @fread($this->handle, $length);
        return ($data === false) ? '' : $data;
    }

    public function readByte(): string
    {
        $this->assertConnected();
        if (feof($this->handle)) {
            throw new AstmException('FileTransport: end of file reached.');
        }
        $byte = @fread($this->handle, 1);
        if ($byte === false || $byte === '') {
            throw new AstmException('FileTransport: read error.');
        }
        return $byte;
    }

    public function isConnected(): bool
    {
        return $this->handle !== null && is_resource($this->handle);
    }

    /** Rewind to the start of the file (read mode only). */
    public function rewind(): void
    {
        $this->assertConnected();
        rewind($this->handle);
    }

    /** Return the current byte offset within the file. */
    public function tell(): int
    {
        $this->assertConnected();
        return (int) ftell($this->handle);
    }

    // -----------------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------------

    private function assertConnected(): void
    {
        if (!$this->isConnected()) {
            throw new AstmException('FileTransport: not connected. Call connect() first.');
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
