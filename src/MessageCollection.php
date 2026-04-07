<?php

declare(strict_types=1);

namespace Astm;

use Astm\Records\Result;

/**
 * An ordered collection of {@see Message} objects.
 *
 * Useful when parsing a file or socket stream that contains multiple sessions
 * (an instrument that sent several batches in a row).
 *
 * Also provides cross-message query helpers, e.g. finding all abnormal results
 * across every message in the collection.
 *
 * Usage:
 *
 *   $collection = MessageCollection::fromFile('/captures/session.astm');
 *
 *   foreach ($collection->getAbnormalResults() as $result) {
 *       printf("%s  %s  flag=%s\n",
 *           $result->getTestName(), $result->getValue(), $result->getAbnormalFlag());
 *   }
 */
final class MessageCollection implements \Countable, \IteratorAggregate
{
    /** @var list<Message> */
    private array $messages;

    /** @param list<Message> $messages */
    public function __construct(array $messages = [])
    {
        $this->messages = array_values($messages);
    }

    // -----------------------------------------------------------------------
    //  Named constructors
    // -----------------------------------------------------------------------

    /**
     * Parse one or more ASTM messages from a raw string (newline or CR separated).
     * Each session is delimited by H … L record pairs.
     */
    public static function fromString(string $raw, bool $strict = false): static
    {
        return static::parseRaw($raw, $strict);
    }

    /**
     * Parse all ASTM messages from a plain-text file (not LLP-framed).
     * For LLP-framed files use {@see \Astm\Transport\FileTransport} with a {@see Receiver}.
     */
    public static function fromFile(string $path, bool $strict = false): static
    {
        if (!file_exists($path)) {
            throw new Exceptions\AstmException("MessageCollection: file not found: '{$path}'");
        }
        return static::parseRaw((string) file_get_contents($path), $strict);
    }

    /**
     * Build a collection from an array of already-parsed Message objects.
     *
     * @param list<Message> $messages
     */
    public static function fromMessages(array $messages): static
    {
        return new static($messages);
    }

    // -----------------------------------------------------------------------
    //  Collection interface
    // -----------------------------------------------------------------------

    public function count(): int
    {
        return count($this->messages);
    }

    /** @return \ArrayIterator<int, Message> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->messages);
    }

    public function isEmpty(): bool
    {
        return empty($this->messages);
    }

    /** @return list<Message> */
    public function all(): array
    {
        return $this->messages;
    }

    public function first(): ?Message
    {
        return $this->messages[0] ?? null;
    }

    public function last(): ?Message
    {
        return !empty($this->messages) ? $this->messages[count($this->messages) - 1] : null;
    }

    public function add(Message $message): static
    {
        $clone           = clone $this;
        $clone->messages = [...$this->messages, $message];
        return $clone;
    }

    // -----------------------------------------------------------------------
    //  Cross-message query helpers
    // -----------------------------------------------------------------------

    /**
     * All Result records across all messages in this collection.
     *
     * @return list<Result>
     */
    public function getAllResults(): array
    {
        return array_merge(...array_map(fn(Message $m) => $m->getResults(), $this->messages));
    }

    /**
     * Results whose abnormal flag is not empty and not 'N'.
     *
     * @return list<Result>
     */
    public function getAbnormalResults(): array
    {
        return array_values(array_filter($this->getAllResults(), fn(Result $r) => $r->isAbnormal()));
    }

    /**
     * Results flagged as High (H or HH).
     *
     * @return list<Result>
     */
    public function getHighResults(): array
    {
        return array_values(array_filter($this->getAllResults(), fn(Result $r) => $r->isHigh()));
    }

    /**
     * Results flagged as Low (L or LL).
     *
     * @return list<Result>
     */
    public function getLowResults(): array
    {
        return array_values(array_filter($this->getAllResults(), fn(Result $r) => $r->isLow()));
    }

    /**
     * Filter results by test name across all messages.
     *
     * @return list<Result>
     */
    public function getResultsByTest(string $testName): array
    {
        return array_values(array_filter(
            $this->getAllResults(),
            fn(Result $r) => strcasecmp($r->getTestName(), $testName) === 0
        ));
    }

    /**
     * Flat map of every result across all messages:
     *   [ 'WBC' => [['value'=>..,'units'=>..,'flag'=>..,'status'=>..], ...], ... ]
     *
     * @return array<string, list<array{value:string,units:string,flag:string,status:string}>>
     */
    public function getAllResultsMapped(): array
    {
        $map = [];
        foreach ($this->getAllResults() as $result) {
            $name = $result->getTestName();
            if ($name === '') {
                continue;
            }
            $map[$name][] = [
                'value'  => $result->getValue(),
                'units'  => $result->getUnits(),
                'flag'   => $result->getAbnormalFlag(),
                'status' => $result->getResultStatus(),
            ];
        }
        return $map;
    }

    /**
     * Messages that have at least one abnormal result.
     *
     * @return list<Message>
     */
    public function getMessagesWithAbnormalities(): array
    {
        return array_values(array_filter(
            $this->messages,
            fn(Message $m) => count(array_filter($m->getResults(), fn(Result $r) => $r->isAbnormal())) > 0
        ));
    }

    // -----------------------------------------------------------------------
    //  Serialisation
    // -----------------------------------------------------------------------

    /**
     * Render all messages back to a CR-terminated wire string.
     * Each message is separated by a CR (matching typical instrument output).
     */
    public function toString(string $recordSep = "\r", string $messageSep = "\r"): string
    {
        return implode($messageSep, array_map(
            fn(Message $m) => $m->toString($recordSep),
            $this->messages
        ));
    }


    // -----------------------------------------------------------------------
    //  Export
    // -----------------------------------------------------------------------

    /**
     * Write all messages in this collection to a plain-text file
     * (one record per line, sessions separated by a blank line).
     *
     * For LLP-framed binary output suitable for replay with a Receiver, use
     * {@see \Astm\Astm::writeFile()} on each message individually.
     */
    public function toFile(string $path, string $recordSep = "\r\n"): void
    {
        $content = implode($recordSep . $recordSep, array_map(
            fn(Message $m) => $m->toString($recordSep),
            $this->messages
        ));
        file_put_contents($path, $content);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    // -----------------------------------------------------------------------
    //  Internal parsing
    // -----------------------------------------------------------------------

    /**
     * Split raw text on H-record boundaries and parse each chunk.
     */
    private static function parseRaw(string $raw, bool $strict): static
    {
        $parser   = new Parser(strict: $strict);
        $messages = [];

        // Normalise line endings
        $normalised = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines      = array_filter(explode("\n", $normalised), fn($l) => trim($l) !== '');

        // Group lines into sessions: each session starts at an H record
        $sessions   = [];
        $current    = [];
        foreach ($lines as $line) {
            if (str_starts_with(ltrim($line), 'H') && !empty($current)) {
                $sessions[] = $current;
                $current    = [];
            }
            $current[] = $line;
        }
        if (!empty($current)) {
            $sessions[] = $current;
        }

        foreach ($sessions as $session) {
            try {
                $messages[] = $parser->parse(implode("\n", $session));
            } catch (Exceptions\ParseException) {
                // Skip malformed sessions
            }
        }

        return new static($messages);
    }
}
