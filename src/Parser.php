<?php

declare(strict_types=1);

namespace Astm;

use Astm\Exceptions\ParseException;
use Astm\Exceptions\UnknownRecordTypeException;
use Astm\Records\AbstractRecord;
use Astm\Records\Comment;
use Astm\Records\Header;
use Astm\Records\Order;
use Astm\Records\Patient;
use Astm\Records\Query;
use Astm\Records\Result;
use Astm\Records\Terminator;

/**
 * Parses a raw ASTM E1394-97 message string into a {@see Message} object.
 *
 * Usage:
 *
 *   $message = (new Parser())->parse($rawString);
 *
 * The parser is lenient about line endings (CR, LF, or CRLF) and silently
 * skips blank lines.  Unknown record-type characters cause an exception by
 * default; pass `strict: false` to the constructor to ignore them instead.
 */
final class Parser
{
    /**
     * Map of record-type character → class name.
     *
     * @var array<string, class-string<AbstractRecord>>
     */
    private static array $recordMap = [
        Header::TYPE     => Header::class,
        Patient::TYPE    => Patient::class,
        Order::TYPE      => Order::class,
        Result::TYPE     => Result::class,
        Comment::TYPE    => Comment::class,
        Query::TYPE      => Query::class,
        Terminator::TYPE => Terminator::class,
    ];

    public function __construct(private readonly bool $strict = true) {}

    // -----------------------------------------------------------------------
    //  Public API
    // -----------------------------------------------------------------------

    /**
     * Parse a raw ASTM string and return a populated Message.
     *
     * @throws ParseException              if the message has no H record.
     * @throws UnknownRecordTypeException  (strict mode only) on unknown record types.
     */
    public function parse(string $raw): Message
    {
        $lines = $this->splitLines($raw);

        if (empty($lines)) {
            throw new ParseException('Empty ASTM message.');
        }

        // The very first non-blank line MUST be the H record so we can
        // extract delimiters from it.
        $firstLine = $lines[0];
        if (!str_starts_with($firstLine, 'H')) {
            throw new ParseException("Expected H record as first line, got: '{$firstLine[0]}'");
        }

        $delimiters = Delimiters::fromHeaderLine($firstLine);
        $message    = new Message();

        foreach ($lines as $line) {
            $record = $this->parseLine($line, $delimiters);
            if ($record !== null) {
                $message->addRecord($record);
            }
        }

        return $message;
    }

    // -----------------------------------------------------------------------
    //  Internal helpers
    // -----------------------------------------------------------------------

    /**
     * Split the raw string on any CR / LF / CRLF combination and strip blanks.
     *
     * @return list<string>
     */
    private function splitLines(string $raw): array
    {
        // Normalise all line endings to \n then split
        $normalised = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines      = explode("\n", $normalised);

        return array_values(array_filter($lines, fn(string $l) => trim($l) !== ''));
    }

    /**
     * Parse one line into a record, or return null if it should be skipped.
     *
     * @throws UnknownRecordTypeException
     */
    private function parseLine(string $line, Delimiters $delimiters): ?AbstractRecord
    {
        $type = strtoupper($line[0] ?? '');

        if (!isset(self::$recordMap[$type])) {
            if ($this->strict) {
                throw new UnknownRecordTypeException($type);
            }
            return null; // skip unknown record in non-strict mode
        }

        $class = self::$recordMap[$type];

        /** @var AbstractRecord $record */
        $record = new $class($delimiters);
        $record->fromLine($line);

        return $record;
    }

    // -----------------------------------------------------------------------
    //  Extension point
    // -----------------------------------------------------------------------

    /**
     * Register a custom record class for a given type character.
     * Useful for vendor-specific record types.
     *
     * @param class-string<AbstractRecord> $class
     */
    public static function registerRecordType(string $type, string $class): void
    {
        self::$recordMap[strtoupper($type)] = $class;
    }
}
