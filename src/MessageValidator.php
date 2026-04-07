<?php

declare(strict_types=1);

namespace Astm;

use Astm\Records\Header;
use Astm\Records\Order;
use Astm\Records\Patient;
use Astm\Records\Result;
use Astm\Records\Terminator;

/**
 * Validates the structural integrity of an ASTM E1394-97 {@see Message}.
 *
 * Checks performed:
 *  - First record is H (Header), last is L (Terminator).
 *  - Message contains at least two records (H and L).
 *  - P, O, Q records have globally consecutive sequence numbers starting at 1.
 *  - R and C records reset their sequence numbers within each parent group
 *    (i.e. R/C numbering restarts after each O record), matching real-world
 *    instrument behaviour.
 *  - Result records have a non-empty Universal Test ID and test name.
 *  - Result status codes are recognised (F/C/P/I/S/X/R/N).
 */
final class MessageValidator
{
    private const VALID_STATUSES = ['F', 'C', 'P', 'I', 'S', 'X', 'R', 'N'];

    /** Record types whose sequence numbers reset per parent group. */
    private const GROUP_SCOPED = ['R', 'C'];

    /** Record types whose sequence numbers are global across the message. */
    private const GLOBAL_SCOPED = ['P', 'O', 'Q'];

    /** @var list<string> */
    private array $errors = [];

    // -----------------------------------------------------------------------
    //  Public API
    // -----------------------------------------------------------------------

    /**
     * Validate the message. Returns true when there are no errors.
     */
    public function validate(Message $message): bool
    {
        $this->errors = [];
        $records      = $message->getRecords();

        if (empty($records)) {
            $this->error('Message contains no records.');
            return false;
        }

        // First record must be H
        if ($records[0]->getType() !== Header::TYPE) {
            $this->error('First record must be H (Header), got: ' . $records[0]->getType());
        }

        // Last record must be L
        $last = $records[count($records) - 1];
        if ($last->getType() !== Terminator::TYPE) {
            $this->error('Last record must be L (Terminator), got: ' . $last->getType());
        }

        if (count($records) < 2) {
            $this->error('Message must contain at least H and L records.');
            return false;
        }

        // ── Sequence number validation ──────────────────────────────────────
        // Global counters for P, O, Q
        $globalSeq = [];
        // Per-group counters for R, C (reset after each O record)
        $groupSeq  = [];

        foreach ($records as $idx => $record) {
            $type = $record->getType();
            if (in_array($type, ['H', 'L'], true)) {
                continue;
            }

            // R records reset per O group; C records reset per any non-C record
            if ($type === Order::TYPE) {
                $groupSeq = []; // R and C both reset
            } elseif ($type !== 'C') {
                // Any non-C record resets the C counter (C is scoped to preceding record)
                unset($groupSeq['C']);
            }

            $seq = (int) $record->getField(2);

            if (in_array($type, self::GLOBAL_SCOPED, true)) {
                $globalSeq[$type] = ($globalSeq[$type] ?? 0) + 1;
                $expected = $globalSeq[$type];
            } elseif (in_array($type, self::GROUP_SCOPED, true)) {
                $groupSeq[$type] = ($groupSeq[$type] ?? 0) + 1;
                $expected = $groupSeq[$type];
            } else {
                // Unknown types — skip sequence check
                continue;
            }

            if ($seq !== $expected) {
                $this->error(sprintf(
                    'Record %d (%s): sequence number is %d, expected %d.',
                    $idx + 1, $type, $seq, $expected
                ));
            }
        }

        // ── Result-specific rules ───────────────────────────────────────────
        foreach ($message->getResults() as $idx => $result) {
            $n = $idx + 1;

            if ($result->getUniversalTestIdRaw() === '' || $result->getUniversalTestIdRaw() === '^^^^') {
                $this->error("Result {$n}: Universal Test ID (field 3) is empty.");
            }

            if ($result->getTestName() === '') {
                $this->error("Result {$n}: could not determine test name from Universal Test ID.");
            }

            $status = $result->getResultStatus();
            if ($status !== '' && !in_array($status, self::VALID_STATUSES, true)) {
                $this->error("Result {$n} ({$result->getTestName()}): unrecognised result status '{$status}'.");
            }

            if ($status === 'F' && $result->getValue() === '' && !$result->isAbnormal()) {
                $this->error("Result {$n} ({$result->getTestName()}): status is Final but value is empty.");
            }
        }

        return empty($this->errors);
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function isValid(Message $message): bool
    {
        return $this->validate($message);
    }

    // -----------------------------------------------------------------------

    private function error(string $msg): void
    {
        $this->errors[] = $msg;
    }
}
