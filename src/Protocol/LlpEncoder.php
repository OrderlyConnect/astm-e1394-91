<?php

declare(strict_types=1);

namespace Astm\Protocol;

use Astm\Message;
use Astm\Records\AbstractRecord;

/**
 * Encodes an ASTM {@see Message} into an ordered list of LLP {@see Frame}s.
 *
 * Per ASTM E1381-95 §7, ETX terminates the last chunk of each record;
 * ETB is used only for intermediate chunks when a record exceeds maxDataBytes.
 * Frame numbers cycle 1–7 across the whole message.
 */
final class LlpEncoder
{
    /** Max record data bytes per frame (ASTM recommends ≤240). */
    public function __construct(private readonly int $maxDataBytes = 240) {}

    /**
     * @param Message $message
     * @return list<Frame>
     */
    public function encode(Message $message): array
    {
        return $this->encodeLines(array_map(
            fn(AbstractRecord $r) => $r->toString(),
            $message->getRecords()
        ));
    }

    /**
     * @param list<string> $lines
     * @return list<Frame>
     */
    public function encodeLines(array $lines): array
    {
        $frames      = [];
        $frameNumber = 1;

        foreach ($lines as $line) {
            $chunks = str_split($line, $this->maxDataBytes) ?: [''];
            $last   = count($chunks) - 1;

            foreach ($chunks as $idx => $chunk) {
                // ETX = last chunk of this record; ETB = intermediate chunk
                $frames[]    = new Frame($chunk, $frameNumber, $idx === $last);
                $frameNumber = ($frameNumber % 7) + 1;
            }
        }

        return $frames;
    }

    public function encodeToString(Message $message): string
    {
        return implode('', array_map(fn(Frame $f) => $f->encode(), $this->encode($message)));
    }
}
