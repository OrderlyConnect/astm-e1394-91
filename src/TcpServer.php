<?php

declare(strict_types=1);

namespace Astm;

use Astm\Exceptions\AstmException;
use Astm\Protocol\LlpDecoder;
use Astm\Transport\StreamTransport;

/**
 * Non-forking TCP server that accepts inbound ASTM instrument connections.
 *
 * Each accepted connection is handled synchronously (one at a time) inside a
 * blocking {@see serve()} loop.  For high-throughput production use, run
 * multiple worker processes or integrate with an async runtime.
 *
 * Usage:
 *
 *   $server = new TcpServer('0.0.0.0', 3001);
 *
 *   // Graceful shutdown on SIGTERM
 *   pcntl_signal(SIGTERM, fn() => $server->stop());
 *
 *   $server->serve(function (Message $msg, string $remoteAddr): void {
 *       echo "[{$remoteAddr}] {$msg->getHeader()->getSenderName()}"
 *          . " — " . count($msg->getResults()) . " results\n";
 *   });
 */
final class TcpServer
{
    /** @var resource|false */
    private mixed $serverSocket = false;
    private bool  $running      = false;

    public function __construct(
        private readonly string $host            = '0.0.0.0',
        private readonly int    $port            = 3001,
        private readonly int    $backlog         = 5,
        private readonly int    $readTimeoutSec  = 60,
        private readonly bool   $verifyChecksums = true,
    ) {}

    // -----------------------------------------------------------------------
    //  Lifecycle
    // -----------------------------------------------------------------------

    /**
     * Bind the server socket.  Called automatically by {@see serve()} if not
     * already bound.
     *
     * @throws AstmException if the port cannot be bound.
     */
    public function bind(): void
    {
        if (is_resource($this->serverSocket)) {
            return;
        }

        $socket = @stream_socket_server(
            "tcp://{$this->host}:{$this->port}",
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
        );

        if ($socket === false) {
            throw new AstmException(
                "TcpServer: cannot bind {$this->host}:{$this->port} — [{$errorCode}] {$errorMessage}"
            );
        }

        stream_set_blocking($socket, true);
        $this->serverSocket = $socket;
    }

    /**
     * Block and accept connections, invoking $handler for each complete Message.
     *
     * @param callable(Message, string $remoteAddr): void $handler
     */
    public function serve(callable $handler): void
    {
        $this->bind();
        $this->running = true;

        while ($this->running) {
            $client = @stream_socket_accept(
                $this->serverSocket,
                $this->readTimeoutSec,
                $peer,
            );

            if ($client === false) {
                // timeout or interrupted — loop and retry
                continue;
            }

            $remoteAddr = (string) $peer;

            try {
                $this->handleConnection($client, $remoteAddr, $handler);
            } catch (\Throwable) {
                // swallow per-connection errors so the server keeps running
            } finally {
                if (is_resource($client)) {
                    fclose($client);
                }
            }
        }
    }

    /** Request a graceful stop after the current connection finishes. */
    public function stop(): void
    {
        $this->running = false;
        $this->close();
    }

    public function close(): void
    {
        if (is_resource($this->serverSocket)) {
            fclose($this->serverSocket);
            $this->serverSocket = false;
        }
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function getAddress(): string
    {
        return "{$this->host}:{$this->port}";
    }

    // -----------------------------------------------------------------------
    //  Per-connection handler
    // -----------------------------------------------------------------------

    /**
     * @param resource $client
     * @param callable(Message, string): void $handler
     */
    private function handleConnection(mixed $client, string $remoteAddr, callable $handler): void
    {
        stream_set_blocking($client, false);
        stream_set_timeout($client, $this->readTimeoutSec);

        $transport = StreamTransport::wrap($client, ownsStream: false);

        $receiver = new Receiver(
            transport:       $transport,
            onMessage:       function (Message $msg) use ($handler, $remoteAddr): void {
                $handler($msg, $remoteAddr);
            },
            verifyChecksums: $this->verifyChecksums,
        );

        $deadline = time() + $this->readTimeoutSec * 10;

        while (time() < $deadline && $transport->isConnected()) {
            $receiver->tick();
            usleep(1_000);
        }
    }
}
