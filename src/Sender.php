<?php

declare(strict_types=1);

namespace Astm;

use Astm\Exceptions\AstmException;
use Astm\Protocol\Ascii;
use Astm\Protocol\Frame;
use Astm\Protocol\LlpEncoder;
use Astm\Transport\TransportInterface;

/**
 * Sends an ASTM {@see Message} over any {@see TransportInterface} using the
 * full ASTM LLP handshake protocol (ENQ → ACK/NAK → frames → EOT).
 *
 * Protocol flow (ASTM E1381-95 §7):
 *
 *  1. Send ENQ.
 *  2. Wait for ACK.  If NAK or timeout, retry up to $maxEnqRetries.
 *  3. For each frame:
 *       a. Send STX … ETX/ETB … checksum CR LF.
 *       b. Wait for ACK.  If NAK, retransmit up to $maxFrameRetries.
 *  4. Send EOT.
 *
 * Usage:
 *
 *   $transport = new TcpTransport('192.168.1.50', 3001);
 *   $sender    = new Sender($transport);
 *   $sender->send($message);
 */
final class Sender
{
    private LlpEncoder $encoder;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly int $maxEnqRetries   = 3,
        private readonly int $maxFrameRetries = 3,
        private readonly int $ackTimeoutMs    = 15_000,
        ?LlpEncoder $encoder = null,
    ) {
        $this->encoder = $encoder ?? new LlpEncoder();
    }

    // -----------------------------------------------------------------------
    //  Public API
    // -----------------------------------------------------------------------

    /**
     * Encode and transmit a Message.
     *
     * @throws AstmException if the handshake fails or frames cannot be ACKed.
     */
    public function send(Message $message): void
    {
        if (!$this->transport->isConnected()) {
            $this->transport->connect();
        }

        $this->establishLink();

        $frames = $this->encoder->encode($message);

        foreach ($frames as $frame) {
            $this->sendFrame($frame);
        }

        $this->terminateLink();
    }

    // -----------------------------------------------------------------------
    //  Internal handshake steps
    // -----------------------------------------------------------------------

    /**
     * Send ENQ and wait for ACK (retrying on NAK or timeout).
     */
    private function establishLink(): void
    {
        for ($attempt = 1; $attempt <= $this->maxEnqRetries; $attempt++) {
            $this->transport->write(Ascii::ENQ);

            $response = $this->readControlByte();

            if ($response === Ascii::ACK) {
                return;
            }

            if ($response === Ascii::NAK) {
                if ($attempt < $this->maxEnqRetries) {
                    usleep(min(100_000 * $attempt, 1_000_000)); // back-off up to 1 s
                    continue;
                }
            }

            throw new AstmException(
                "Sender: receiver did not ACK after {$this->maxEnqRetries} ENQ attempts. "
                . 'Last response: 0x' . strtoupper(bin2hex($response))
            );
        }
    }

    /**
     * Send one frame and wait for ACK (retrying on NAK).
     */
    private function sendFrame(Frame $frame): void
    {
        $binary = $frame->encode();

        for ($attempt = 1; $attempt <= $this->maxFrameRetries; $attempt++) {
            $this->transport->write($binary);

            $response = $this->readControlByte();

            if ($response === Ascii::ACK) {
                return;
            }

            if ($response === Ascii::NAK && $attempt < $this->maxFrameRetries) {
                continue; // retransmit
            }

            throw new AstmException(
                "Sender: frame {$frame->frameNumber} not ACKed after {$this->maxFrameRetries} attempts."
            );
        }
    }

    private function terminateLink(): void
    {
        $this->transport->write(Ascii::EOT);
    }

    // -----------------------------------------------------------------------
    //  Read helpers
    // -----------------------------------------------------------------------

    /**
     * Read bytes until we see a meaningful control character, discarding
     * CR/LF and other noise.
     */
    private function readControlByte(): string
    {
        $deadline = microtime(true) + ($this->ackTimeoutMs / 1000);

        while (microtime(true) < $deadline) {
            $byte = $this->transport->readByte();

            if (in_array($byte, [Ascii::ACK, Ascii::NAK, Ascii::ENQ, Ascii::EOT], true)) {
                return $byte;
            }
            // discard CR, LF, other noise and keep reading
        }

        throw new AstmException(
            "Sender: timeout ({$this->ackTimeoutMs} ms) waiting for control byte from receiver."
        );
    }
}
