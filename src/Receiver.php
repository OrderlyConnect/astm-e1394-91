<?php

declare(strict_types=1);

namespace Astm;

use Astm\Exceptions\ParseException;
use Astm\Protocol\Ascii;
use Astm\Protocol\Frame;
use Astm\Transport\TransportInterface;

/**
 * Receives ASTM E1394-97 messages over any {@see TransportInterface}.
 *
 * Implements the full LLP handshake (E1381-95 §7):
 *   1. Wait for ENQ  → respond ACK.
 *   2. For each frame: validate checksum → ACK (or NAK to request retransmit).
 *   3. Accumulate record lines until the L (Terminator) record is received.
 *   4. On EOT (or after L record): parse and emit the complete Message.
 *
 * Wire frame layout (one frame per record, or ETB-split for long records):
 *   STX  frameNum(1 byte)  data  ETX|ETB  C1 C2  CR  LF
 *
 * Usage — blocking:
 *
 *   $receiver = new Receiver($transport, onMessage: function (Message $msg) {
 *       // handle each complete message
 *   });
 *   $receiver->listen(); // blocks forever
 *
 * Usage — non-blocking / event loop:
 *
 *   while (true) {
 *       $receiver->tick();
 *       while ($msg = $receiver->popMessage()) { ... }
 *   }
 */
final class Receiver
{
    // -----------------------------------------------------------------------
    //  Internal state-machine states
    // -----------------------------------------------------------------------
    private const S_IDLE          = 0; // waiting for ENQ
    private const S_RECEIVING     = 1; // session open, waiting for STX or EOT
    private const S_FRAME_NUM     = 2; // consuming the 1-byte frame number
    private const S_IN_FRAME      = 3; // reading record data bytes
    private const S_CHECKSUM_1    = 4; // reading first checksum byte
    private const S_CHECKSUM_2    = 5; // reading second checksum byte
    private const S_AFTER_CS      = 6; // consuming CR LF after checksum

    private Parser $parser;

    private int    $state        = self::S_IDLE;
    private string $frameData    = '';  // data bytes of current frame
    private string $frameNum     = ''; // single frame-number char
    private string $termChar     = ''; // ETX or ETB
    private string $cs1          = ''; // first checksum char
    private string $currentLine  = ''; // accumulates ETB chunks → one record line
    /** @var list<string> */
    private array  $lines        = []; // completed record lines for current session

    /** @var callable(Message): void|null */
    private mixed $onMessage;

    /** @var list<Message> */
    private array $messageQueue = [];

    public function __construct(
        private readonly TransportInterface $transport,
        callable|null                       $onMessage       = null,
        private readonly bool               $verifyChecksums = true,
        ?Parser                             $parser          = null,
    ) {
        $this->onMessage = $onMessage;
        $this->parser    = $parser ?? new Parser();
    }

    // -----------------------------------------------------------------------
    //  Public API
    // -----------------------------------------------------------------------

    /** Block and process incoming data indefinitely. */
    public function listen(): void
    {
        if (!$this->transport->isConnected()) {
            $this->transport->connect();
        }
        while (true) {
            $this->tick();
        }
    }

    /** Process whatever bytes are available right now (non-blocking). */
    public function tick(): void
    {
        $chunk = $this->transport->read(512);
        foreach (str_split($chunk) as $byte) {
            $this->processByte($byte);
        }
    }

    /** Pop the next decoded Message, or null if none ready. */
    public function popMessage(): ?Message
    {
        return array_shift($this->messageQueue) ?? null;
    }

    public function hasMessage(): bool
    {
        return !empty($this->messageQueue);
    }

    // -----------------------------------------------------------------------
    //  Byte-level state machine
    // -----------------------------------------------------------------------

    private function processByte(string $byte): void
    {
        switch ($this->state) {

            // -----------------------------------------------------------------
            case self::S_IDLE:
                if ($byte === Ascii::ENQ) {
                    $this->transport->write(Ascii::ACK);
                    $this->state = self::S_RECEIVING;
                    $this->resetSession();
                }
                break;

            // -----------------------------------------------------------------
            case self::S_RECEIVING:
                if ($byte === Ascii::STX) {
                    $this->frameData = '';
                    $this->frameNum  = '';
                    $this->termChar  = '';
                    $this->state     = self::S_FRAME_NUM;
                } elseif ($byte === Ascii::EOT) {
                    $this->finaliseSession();
                } elseif ($byte === Ascii::ENQ) {
                    // Receiver is busy — shouldn't happen, send NAK
                    $this->transport->write(Ascii::NAK);
                }
                // CR, LF, other control bytes between frames → ignore
                break;

            // -----------------------------------------------------------------
            case self::S_FRAME_NUM:
                // First byte after STX is the frame number (1 char, '0'–'7')
                $this->frameNum = $byte;
                $this->state    = self::S_IN_FRAME;
                break;

            // -----------------------------------------------------------------
            case self::S_IN_FRAME:
                if ($byte === Ascii::ETX || $byte === Ascii::ETB) {
                    $this->termChar = $byte;
                    $this->cs1      = '';
                    $this->state    = self::S_CHECKSUM_1;
                } else {
                    $this->frameData .= $byte;
                }
                break;

            // -----------------------------------------------------------------
            case self::S_CHECKSUM_1:
                $this->cs1   = $byte;
                $this->state = self::S_CHECKSUM_2;
                break;

            // -----------------------------------------------------------------
            case self::S_CHECKSUM_2:
                $receivedCs = $this->cs1 . $byte;

                if ($this->verifyChecksums) {
                    $payload   = $this->frameNum . $this->frameData . $this->termChar;
                    $expectedCs = Frame::checksum($payload);
                    if (strtoupper($receivedCs) !== $expectedCs) {
                        // Bad checksum → NAK (sender should retransmit)
                        $this->transport->write(Ascii::NAK);
                        $this->state = self::S_AFTER_CS;
                        return;
                    }
                }

                // Good frame — accumulate data
                $this->currentLine .= $this->frameData;

                if ($this->termChar === Ascii::ETX) {
                    // Record is complete
                    $this->lines[]     = $this->currentLine;
                    $this->currentLine = '';

                    // If this is the L (Terminator) record, finish the session now
                    // (some instruments omit EOT; handle both cases)
                    $lastLine = end($this->lines);
                    if ($lastLine !== false && str_starts_with(ltrim($lastLine), 'L')) {
                        $this->transport->write(Ascii::ACK);
                        $this->state = self::S_AFTER_CS;
                        $this->finaliseSession();
                        return;
                    }
                }
                // ETB = more chunks for this record line → keep accumulating

                $this->transport->write(Ascii::ACK);
                $this->state = self::S_AFTER_CS;
                break;

            // -----------------------------------------------------------------
            case self::S_AFTER_CS:
                // Consume CR and LF after checksum
                if ($byte === Ascii::LF) {
                    $this->state = self::S_RECEIVING;
                }
                // CR or anything else: stay in S_AFTER_CS waiting for LF
                break;
        }
    }

    // -----------------------------------------------------------------------
    //  Session management
    // -----------------------------------------------------------------------

    private function resetSession(): void
    {
        $this->lines       = [];
        $this->currentLine = '';
        $this->frameData   = '';
    }

    private function finaliseSession(): void
    {
        if (!empty($this->lines)) {
            try {
                $message              = $this->parser->parse(implode("\n", $this->lines));
                $this->messageQueue[] = $message;
                if ($this->onMessage !== null) {
                    ($this->onMessage)($message);
                }
            } catch (ParseException) {
                // malformed message — drop silently
            }
        }
        $this->resetSession();
        $this->state = self::S_IDLE;
    }
}
