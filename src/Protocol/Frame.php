<?php

declare(strict_types=1);

namespace Astm\Protocol;

/**
 * Represents a single LLP data frame (immutable).
 *
 * Wire layout:
 *   STX  frameNumber(1 char, '0'–'7')  data  ETX|ETB  C1 C2  CR  LF
 *
 * The checksum covers every byte from frameNumber through ETX/ETB inclusive.
 */
final class Frame
{
    /**
     * @param string $data         Raw ASTM record line (no CR/LF).
     * @param int    $frameNumber  1–7 (cycles via mod 8).
     * @param bool   $isLast       true → ETX terminator, false → ETB.
     */
    public function __construct(
        public readonly string $data,
        public readonly int    $frameNumber,
        public readonly bool   $isLast = true,
    ) {}

    /** Build the binary frame string ready to put on the wire. */
    public function encode(): string
    {
        $fn       = (string) ($this->frameNumber % 8);   // cycles 0-7
        $termChar = $this->isLast ? Ascii::ETX : Ascii::ETB;
        $payload  = $fn . $this->data . $termChar;
        $checksum = self::checksum($payload);

        return Ascii::STX . $payload . $checksum . Ascii::CR . Ascii::LF;
    }

    // -----------------------------------------------------------------------
    //  Checksum
    // -----------------------------------------------------------------------

    /**
     * ASTM checksum: sum of ASCII values of all bytes in $payload, mod 256,
     * formatted as two uppercase hexadecimal characters.
     *
     * $payload = frameNumber . data . ETX|ETB
     */
    public static function checksum(string $payload): string
    {
        $sum = 0;
        $len = strlen($payload);
        for ($i = 0; $i < $len; $i++) {
            $sum += ord($payload[$i]);
        }
        return strtoupper(sprintf('%02X', $sum % 256));
    }

    /**
     * Verify a received checksum against a payload.
     */
    public static function verifyChecksum(string $payload, string $receivedChecksum): bool
    {
        return self::checksum($payload) === strtoupper($receivedChecksum);
    }
}
