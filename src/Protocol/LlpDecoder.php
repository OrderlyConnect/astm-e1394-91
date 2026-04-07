<?php

declare(strict_types=1);

namespace Astm\Protocol;

use Astm\Exceptions\ParseException;

/**
 * Decodes a raw LLP byte stream into plain ASTM record lines.
 *
 * Can be used in one-shot mode via {@see decode()} or fed incrementally via
 * {@see feed()} + {@see popMessage()} for streaming / event-loop integration.
 *
 * Wire frame layout (E1381-95):
 *   STX  frameNum(1 byte)  data  ETX|ETB  C1 C2  CR  LF
 *
 * - ETB = intermediate chunk of a long record (accumulate, don't flush).
 * - ETX = last chunk of a record (flush to lines[]).
 * - EOT = end of session (finalise message from accumulated lines).
 */
final class LlpDecoder
{
    private const S_HUNT        = 0; // looking for STX (or EOT)
    private const S_FRAME_NUM   = 1; // consuming 1-byte frame number
    private const S_IN_FRAME    = 2; // reading record data
    private const S_CHECKSUM_1  = 3;
    private const S_CHECKSUM_2  = 4;
    private const S_SKIP_CRLF   = 5;

    private int    $state       = self::S_HUNT;
    private string $frameNum    = '';
    private string $frameData   = '';
    private string $termChar    = '';
    private string $cs1         = '';
    private string $currentLine = ''; // accumulates ETB chunks
    /** @var list<string> */
    private array  $lines       = [];
    /** @var list<string> Fully assembled raw message strings. */
    private array  $messages    = [];

    public function __construct(private readonly bool $verifyChecksums = true) {}

    // -----------------------------------------------------------------------
    //  One-shot
    // -----------------------------------------------------------------------

    /**
     * Decode a complete binary blob (may contain multiple LLP sessions).
     *
     * @return list<string>  Each element is a newline-delimited raw ASTM message.
     * @throws ParseException on checksum mismatch (verifyChecksums=true).
     */
    public function decode(string $raw): array
    {
        $this->feed($raw);
        $result = [];
        while (null !== ($msg = $this->popMessage())) {
            $result[] = $msg;
        }
        return $result;
    }

    // -----------------------------------------------------------------------
    //  Streaming
    // -----------------------------------------------------------------------

    /** @throws ParseException */
    public function feed(string $chunk): void
    {
        foreach (str_split($chunk) as $byte) {
            $this->processByte($byte);
        }
    }

    public function popMessage(): ?string
    {
        return array_shift($this->messages) ?? null;
    }

    public function hasMessage(): bool
    {
        return !empty($this->messages);
    }

    // -----------------------------------------------------------------------
    //  State machine
    // -----------------------------------------------------------------------

    private function processByte(string $byte): void
    {
        switch ($this->state) {

            case self::S_HUNT:
                if ($byte === Ascii::STX) {
                    $this->frameData = '';
                    $this->frameNum  = '';
                    $this->state     = self::S_FRAME_NUM;
                } elseif ($byte === Ascii::EOT) {
                    $this->finaliseMessage();
                }
                // ENQ / ACK / NAK / CR / LF → ignore (control noise)
                break;

            case self::S_FRAME_NUM:
                $this->frameNum = $byte;
                $this->state    = self::S_IN_FRAME;
                break;

            case self::S_IN_FRAME:
                if ($byte === Ascii::ETX || $byte === Ascii::ETB) {
                    $this->termChar = $byte;
                    $this->cs1      = '';
                    $this->state    = self::S_CHECKSUM_1;
                } else {
                    $this->frameData .= $byte;
                }
                break;

            case self::S_CHECKSUM_1:
                $this->cs1   = $byte;
                $this->state = self::S_CHECKSUM_2;
                break;

            case self::S_CHECKSUM_2:
                $receivedCs = $this->cs1 . $byte;

                if ($this->verifyChecksums) {
                    $payload = $this->frameNum . $this->frameData . $this->termChar;
                    if (!Frame::verifyChecksum($payload, $receivedCs)) {
                        throw new ParseException(sprintf(
                            'LLP checksum mismatch in frame "%s": expected %s, got %s',
                            $this->frameNum,
                            Frame::checksum($payload),
                            strtoupper($receivedCs)
                        ));
                    }
                }

                $this->currentLine .= $this->frameData;

                if ($this->termChar === Ascii::ETX) {
                    // Complete record line
                    $this->lines[]     = $this->currentLine;
                    $this->currentLine = '';

                    // Some instruments omit EOT — treat L record as session end
                    $last = end($this->lines);
                    if ($last !== false && str_starts_with(ltrim($last), 'L')) {
                        $this->finaliseMessage();
                        $this->state = self::S_HUNT;
                        return;
                    }
                }
                // ETB → keep accumulating currentLine

                $this->state = self::S_SKIP_CRLF;
                break;

            case self::S_SKIP_CRLF:
                if ($byte === Ascii::LF) {
                    $this->state = self::S_HUNT;
                }
                // CR → stay in S_SKIP_CRLF
                break;
        }
    }

    private function finaliseMessage(): void
    {
        if (!empty($this->lines)) {
            $this->messages[]  = implode("\n", $this->lines);
            $this->lines       = [];
            $this->currentLine = '';
        }
    }
}
