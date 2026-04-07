<?php

declare(strict_types=1);

namespace Astm;

/**
 * Represents the set of delimiters used in an ASTM message.
 *
 * Per E1394-97 §5.6.1, the H record's second field encodes four delimiters:
 *   position 1 → component delimiter  (default ^)
 *   position 2 → repeat delimiter     (default \)
 *   position 3 → escape delimiter     (default &)
 *   The field delimiter itself        (default |) is always the first character
 *   after the record-type identifier.
 */
final class Delimiters
{
    public function __construct(
        public readonly string $field     = '|',
        public readonly string $component = '^',
        public readonly string $repeat    = '\\',
        public readonly string $escape    = '&',
    ) {}

    /**
     * Parse delimiters from the raw H record line.
     *
     * H|\^&|...
     *   ^--- field[0] after "H" is the field delimiter
     *        field[1] is the 3-char string "component repeat escape"
     */
    public static function fromHeaderLine(string $line): self
    {
        if (!str_starts_with($line, 'H')) {
            throw new Exceptions\AstmException('Cannot parse delimiters: line is not an H record.');
        }

        $fieldDelimiter = $line[1] ?? '|';
        $encodingChars  = substr($line, 2, 3); // e.g. "\^&"

        return new self(
            field:     $fieldDelimiter,
            component: $encodingChars[1] ?? '^',
            repeat:    $encodingChars[0] ?? '\\',
            escape:    $encodingChars[2] ?? '&',
        );
    }

    /**
     * Returns the encoding characters string as it appears in the H record.
     * e.g.  \^&
     */
    public function encodingChars(): string
    {
        return $this->repeat . $this->component . $this->escape;
    }
}
