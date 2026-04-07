<?php

declare(strict_types=1);

namespace Astm\Transport;

use Astm\Exceptions\AstmException;
use Astm\Exceptions\ConnectionException;

/**
 * RS-232 / serial-port transport.
 *
 * Opens a serial device (e.g. /dev/ttyUSB0 on Linux, COM3 on Windows) and
 * configures it with `stty` for raw binary communication at the baud rate
 * required by the instrument.
 *
 * Typical ASTM instrument settings:
 *   - Baud:     9600 (older) or 19200 / 115200 (newer)
 *   - Data bits: 8
 *   - Parity:    none
 *   - Stop bits: 1
 *
 * Usage:
 *
 *   $transport = new SerialTransport('/dev/ttyUSB0', baud: 9600);
 *   $sender    = new \Astm\Sender($transport);
 *   $sender->send($message);
 */
final class SerialTransport implements TransportInterface
{
    /** @var resource|null */
    private mixed $handle = null;

    /**
     * @param string $device      Device path  e.g. /dev/ttyUSB0 or COM3.
     * @param int    $baud        Baud rate (1200 | 2400 | 4800 | 9600 | 19200 | 38400 | 57600 | 115200).
     * @param int    $dataBits    5 | 6 | 7 | 8.
     * @param string $parity      'none' | 'odd' | 'even'.
     * @param int    $stopBits    1 | 2.
     * @param int    $readTimeout Seconds to wait for incoming bytes.
     */
    public function __construct(
        private readonly string $device,
        private readonly int    $baud        = 9600,
        private readonly int    $dataBits    = 8,
        private readonly string $parity      = 'none',
        private readonly int    $stopBits    = 1,
        private readonly int    $readTimeout = 30,
    ) {}

    // -----------------------------------------------------------------------
    //  TransportInterface
    // -----------------------------------------------------------------------

    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        if (!file_exists($this->device)) {
            throw new AstmException("SerialTransport: device '{$this->device}' not found.");
        }

        // Configure the port with stty before opening
        $this->configurePort();

        $handle = @fopen($this->device, 'r+b');
        if ($handle === false) {
            throw new ConnectionException("SerialTransport: cannot open '{$this->device}'.");
        }

        stream_set_blocking($handle, false);
        stream_set_timeout($handle, $this->readTimeout);

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

        $total  = strlen($data);
        $offset = 0;
        while ($offset < $total) {
            $written = @fwrite($this->handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new ConnectionException('SerialTransport: write error.');
            }
            $offset += $written;
        }
    }

    public function read(int $length = 1024): string
    {
        $this->assertConnected();
        $data = @fread($this->handle, $length);
        return ($data === false) ? '' : $data;
    }

    public function readByte(): string
    {
        $this->assertConnected();
        $deadline = microtime(true) + $this->readTimeout;

        while (microtime(true) < $deadline) {
            $byte = @fread($this->handle, 1);
            if ($byte !== false && $byte !== '') {
                return $byte;
            }
            usleep(1_000);
        }

        throw new ConnectionException('SerialTransport: read timeout.');
    }

    public function isConnected(): bool
    {
        return $this->handle !== null && is_resource($this->handle);
    }

    // -----------------------------------------------------------------------
    //  Port configuration
    // -----------------------------------------------------------------------

    /**
     * Run `stty` to set up the serial port.
     * Requires the `stty` utility (standard on Linux/macOS).
     */
    private function configurePort(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            // Windows: use mode.com
            $parityFlag = match ($this->parity) {
                'odd'  => 'ODD',
                'even' => 'EVEN',
                default => 'NONE',
            };
            $cmd = sprintf(
                'MODE %s: BAUD=%d PARITY=%s DATA=%d STOP=%d',
                $this->device,
                $this->baud,
                $parityFlag[0], // N/E/O
                $this->dataBits,
                $this->stopBits,
            );
        } else {
            // Linux / macOS: use stty
            $parityFlag = match ($this->parity) {
                'odd'  => '-parenb parodd',
                'even' => 'parenb -parodd',
                default => '-parenb',
            };
            $cmd = sprintf(
                'stty -F %s %d cs%d %s %sstopb raw -echo 2>&1',
                escapeshellarg($this->device),
                $this->baud,
                $this->dataBits,
                $parityFlag,
                $this->stopBits === 2 ? '' : '-',
            );
        }

        exec($cmd, $output, $code);
        if ($code !== 0) {
            throw new ConnectionException(
                "SerialTransport: stty failed (exit {$code}): " . implode(' ', $output)
            );
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
