<?php

declare(strict_types=1);

namespace Astm;

/**
 * Encodes and decodes ASTM escape sequences in field values.
 *
 * ASTM E1394-97 §6.6 defines four escape sequences that allow literal
 * delimiter characters to appear inside field values:
 *
 *   &F&   → literal field delimiter      (|)
 *   &R&   → literal repeat delimiter     (\)
 *   &S&   → literal component delimiter  (^)
 *   &E&   → literal escape delimiter     (&)
 *
 * Where & is the configured escape character (default &).
 *
 * Usage:
 *
 *   $codec  = new EscapeCodec($delimiters);
 *   $safe   = $codec->encode("Result with | pipe and ^ caret");
 *   $plain  = $codec->decode($safe);
 */
final class EscapeCodec
{
    public function __construct(private readonly Delimiters $delimiters) {}

    // -----------------------------------------------------------------------
    //  Public API
    // -----------------------------------------------------------------------

    /**
     * Encode a raw string for safe embedding in an ASTM field value.
     * Delimiter characters are replaced with their escape sequences.
     */
    public function encode(string $raw): string
    {
        $e = $this->delimiters->escape;

        // Order matters: encode the escape character itself first to avoid
        // double-encoding the sequences we are about to introduce.
        return str_replace(
            [
                $e,
                $this->delimiters->field,
                $this->delimiters->repeat,
                $this->delimiters->component,
            ],
            [
                $e . 'E' . $e,
                $e . 'F' . $e,
                $e . 'R' . $e,
                $e . 'S' . $e,
            ],
            $raw,
        );
    }

    /**
     * Decode ASTM escape sequences back to their literal characters.
     */
    public function decode(string $encoded): string
    {
        $e = $this->delimiters->escape;

        return str_replace(
            [
                $e . 'F' . $e,
                $e . 'R' . $e,
                $e . 'S' . $e,
                $e . 'E' . $e,
            ],
            [
                $this->delimiters->field,
                $this->delimiters->repeat,
                $this->delimiters->component,
                $this->delimiters->escape,
            ],
            $encoded,
        );
    }

    /**
     * Decode the value of a specific field in a record, returning plain text.
     */
    public function decodeField(Records\AbstractRecord $record, int $fieldN): string
    {
        return $this->decode($record->getField($fieldN));
    }
}
