<?php

declare(strict_types=1);

namespace Astm;

use Astm\DateTimeHelper;
use Astm\MessageDiff;
use Astm\Protocol\LlpEncoder;
use Astm\Transport\FileTransport;
use Astm\Transport\TcpTransport;
use Astm\Transport\TransportInterface;

/**
 * Static façade providing the most convenient entry points into the library.
 *
 * All methods are thin wrappers around the underlying classes; use those
 * directly when you need finer-grained control.
 *
 * -------------------------------------------------------------------------
 * Parsing
 * -------------------------------------------------------------------------
 *
 *   // Parse a raw ASTM string (plain-text, not LLP-framed)
 *   $message = Astm::parse($rawString);
 *
 *   // Parse a plain-text file containing one or more messages
 *   $collection = Astm::parseFile('/captures/session.txt');
 *
 *   // Decode an LLP-framed binary blob (TCP / serial capture)
 *   $collection = Astm::decodeLlp($binaryBlob);
 *
 * -------------------------------------------------------------------------
 * Building
 * -------------------------------------------------------------------------
 *
 *   $message = Astm::build()
 *       ->sender('MY-LIS', 'v1.0')
 *       ->patient(fn($p) => $p->id('P001')->name('Doe', 'Jane')->sex('F'))
 *       ->order(fn($o) => $o->specimenId('S-123')->addTest('WBC'))
 *       ->result(fn($r) => $r->test('WBC')->value('7.2')->units('10*3/uL')->flag('N'))
 *       ->build();
 *
 * -------------------------------------------------------------------------
 * Sending
 * -------------------------------------------------------------------------
 *
 *   // Send over TCP (blocks until complete)
 *   Astm::sendTcp($message, '192.168.1.50', 3001);
 *
 *   // Send via any custom transport
 *   Astm::send($message, $transport);
 *
 *   // Write LLP-framed bytes to a file
 *   Astm::writeFile($message, '/tmp/output.astm');
 *
 * -------------------------------------------------------------------------
 * Receiving (listening server)
 * -------------------------------------------------------------------------
 *
 *   Astm::listen('0.0.0.0', 3001, function (Message $msg, string $from): void {
 *       // handle each message as it arrives
 *   });
 */
final class Astm
{
    private function __construct() {}

    // -----------------------------------------------------------------------
    //  Parsing
    // -----------------------------------------------------------------------

    /**
     * Parse a raw ASTM string (plain text, no LLP framing) into a Message.
     *
     * @throws Exceptions\ParseException on structural errors.
     */
    public static function parse(string $raw, bool $strict = false): Message
    {
        return (new Parser(strict: $strict))->parse($raw);
    }

    /**
     * Parse a plain-text ASTM file (no LLP framing) into a MessageCollection.
     * Handles files with multiple H…L sessions.
     */
    public static function parseFile(string $path, bool $strict = false): MessageCollection
    {
        return MessageCollection::fromFile($path, $strict);
    }

    /**
     * Decode an LLP-framed binary blob (e.g. captured TCP traffic) into a
     * MessageCollection.  The blob may contain multiple sessions.
     */
    public static function decodeLlp(string $binary, bool $verifyChecksums = true): MessageCollection
    {
        $decoder  = new Protocol\LlpDecoder($verifyChecksums);
        $parser   = new Parser();
        $rawMsgs  = $decoder->decode($binary);
        $messages = [];
        foreach ($rawMsgs as $raw) {
            try {
                $messages[] = $parser->parse($raw);
            } catch (Exceptions\ParseException) {
                // skip malformed sessions
            }
        }
        return MessageCollection::fromMessages($messages);
    }

    // -----------------------------------------------------------------------
    //  Building
    // -----------------------------------------------------------------------

    /**
     * Return a fresh {@see MessageBuilder} ready to construct a new message.
     */
    public static function build(?Delimiters $delimiters = null): MessageBuilder
    {
        return MessageBuilder::create($delimiters);
    }

    // -----------------------------------------------------------------------
    //  Sending
    // -----------------------------------------------------------------------

    /**
     * Send a Message over a TCP connection and close it when done.
     *
     * @param int $connectTimeout Seconds to wait for the TCP connection.
     * @param int $readTimeout    Seconds to wait for each ACK.
     */
    public static function sendTcp(
        Message $message,
        string  $host,
        int     $port            = 3001,
        int     $connectTimeout  = 10,
        int     $readTimeout     = 30,
    ): void {
        $transport = new TcpTransport($host, $port, $connectTimeout, $readTimeout);
        $transport->connect();
        try {
            (new Sender($transport))->send($message);
        } finally {
            $transport->disconnect();
        }
    }

    /**
     * Send a Message via any {@see TransportInterface}.
     * The caller is responsible for connecting/disconnecting the transport.
     */
    public static function send(Message $message, TransportInterface $transport): void
    {
        (new Sender($transport))->send($message);
    }

    /**
     * Encode a Message to LLP-framed binary and write it to a file.
     * The ENQ / EOT session wrapper is included so the file can be replayed
     * with a {@see Receiver} or a real instrument.
     */
    public static function writeFile(Message $message, string $path): void
    {
        $encoder   = new LlpEncoder();
        $transport = FileTransport::forWriting($path);
        $transport->connect();
        try {
            // Write full session: ENQ + frames + EOT
            $transport->write(Protocol\Ascii::ENQ);
            foreach ($encoder->encode($message) as $frame) {
                $transport->write($frame->encode());
            }
            $transport->write(Protocol\Ascii::EOT);
        } finally {
            $transport->disconnect();
        }
    }

    /**
     * Read and decode an LLP-framed file written by {@see writeFile()}.
     * Returns a MessageCollection (one message per session in the file).
     */
    public static function readFile(string $path, bool $verifyChecksums = true): MessageCollection
    {
        if (!file_exists($path)) {
            throw new Exceptions\AstmException("Astm::readFile — file not found: '{$path}'");
        }
        return static::decodeLlp((string) file_get_contents($path), $verifyChecksums);
    }

    // -----------------------------------------------------------------------
    //  Receiving / server
    // -----------------------------------------------------------------------

    /**
     * Start a blocking TCP server that calls $handler for each received Message.
     *
     * @param callable(Message, string $remoteAddr): void $handler
     */
    public static function listen(
        callable $handler,
        string   $host = '0.0.0.0',
        int      $port = 3001,
    ): void {
        (new TcpServer($host, $port))->serve($handler);
    }

    // -----------------------------------------------------------------------
    //  Utility
    // -----------------------------------------------------------------------

    /**
     * Create an {@see EscapeCodec} for the given (or default) delimiters.
     */
    public static function escapeCodec(?Delimiters $delimiters = null): EscapeCodec
    {
        return new EscapeCodec($delimiters ?? new Delimiters());
    }


    /**
     * Clone an existing Message into a new {@see MessageBuilder}, ready to
     * modify and re-build.
     *
     *   $enriched = Astm::modify($received)
     *       ->comment('Countersigned by LIS')
     *       ->build();
     */
    public static function modify(Message $message, ?Delimiters $delimiters = null): MessageBuilder
    {
        return MessageBuilder::fromMessage($message, $delimiters);
    }


    // -----------------------------------------------------------------------
    //  Diff
    // -----------------------------------------------------------------------

    /**
     * Compare two messages field-by-field and return a {@see MessageDiff}.
     *
     *   $diff = Astm::diff($original, $corrected);
     *   foreach ($diff->getSummary() as $line) { echo $line . "\n"; }
     */
    public static function diff(Message $a, Message $b): MessageDiff
    {
        return MessageDiff::compare($a, $b);
    }

    // -----------------------------------------------------------------------
    //  Timestamps
    // -----------------------------------------------------------------------

    /**
     * Parse an ASTM timestamp string into a DateTimeImmutable.
     * Accepts YYYYMMDDHHMMSS, YYYYMMDDHHMM, or YYYYMMDD.
     */
    public static function parseDateTime(string $raw): ?\DateTimeImmutable
    {
        return DateTimeHelper::parse($raw);
    }

    /**
     * Format a DateTimeImmutable as an ASTM timestamp string (YYYYMMDDHHMMSS).
     */
    public static function formatDateTime(\DateTimeImmutable $dt): string
    {
        return DateTimeHelper::format($dt);
    }

    /**
     * Current UTC time as an ASTM timestamp string.
     */
    public static function now(): string
    {
        return DateTimeHelper::now();
    }

    /**
     * Library version.
     */
    public static function version(): string
    {
        return '1.0.0';
    }

    /**
     * Validate a Message and return the error list (empty = valid).
     *
     * @return list<string>
     */
    public static function validate(Message $message): array
    {
        $v = new MessageValidator();
        $v->validate($message);
        return $v->getErrors();
    }
}
