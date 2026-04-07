<?php

declare(strict_types=1);

namespace Astm;

use Astm\Records\AbstractRecord;

/**
 * Compares two {@see Message} objects field-by-field and reports differences.
 *
 * Typical use-case: a LIS receives a corrected result (status='C') from an
 * instrument and needs to know exactly which fields changed compared to the
 * original final result.
 *
 * Usage:
 *
 *   $diff = MessageDiff::compare($original, $corrected);
 *
 *   if ($diff->hasDifferences()) {
 *       foreach ($diff->getChangedResults() as $change) {
 *           printf("%-10s  was %-10s  now %s\n",
 *               $change['test'], $change['old']['value'], $change['new']['value']);
 *       }
 *   }
 */
final class MessageDiff
{
    /**
     * @param list<array{type:string,seq:string,field:int,old:string,new:string}> $fieldChanges
     * @param list<array{test:string,old:array<string,string>,new:array<string,string>}> $resultChanges
     * @param list<string> $addedTypes
     * @param list<string> $removedTypes
     */
    private function __construct(
        private readonly array $fieldChanges,
        private readonly array $resultChanges,
        private readonly array $addedTypes,
        private readonly array $removedTypes,
    ) {}

    // -----------------------------------------------------------------------
    //  Factory
    // -----------------------------------------------------------------------

    /**
     * Compare two messages and return a diff object.
     */
    public static function compare(Message $a, Message $b): static
    {
        $fieldChanges  = [];
        $resultChanges = [];
        $addedTypes    = [];
        $removedTypes  = [];

        // Index records by type+seq for $a
        $aIndex = self::indexRecords($a);
        $bIndex = self::indexRecords($b);

        // Records present in $a but not $b
        foreach (array_keys($aIndex) as $key) {
            if (!isset($bIndex[$key])) {
                [$type] = explode(':', $key, 2);
                $removedTypes[] = $type;
            }
        }

        // Records present in $b but not $a
        foreach (array_keys($bIndex) as $key) {
            if (!isset($aIndex[$key])) {
                [$type] = explode(':', $key, 2);
                $addedTypes[] = $type;
            }
        }

        // Compare records present in both
        foreach ($aIndex as $key => $aRecord) {
            if (!isset($bIndex[$key])) {
                continue;
            }
            $bRecord = $bIndex[$key];

            $aFields = $aRecord->getFields();
            $bFields = $bRecord->getFields();
            $maxLen  = max(count($aFields), count($bFields));

            for ($i = 0; $i < $maxLen; $i++) {
                $aVal = $aFields[$i] ?? '';
                $bVal = $bFields[$i] ?? '';
                if ($aVal !== $bVal) {
                    $fieldChanges[] = [
                        'type'  => $aRecord->getType(),
                        'seq'   => $aRecord->getField(2),
                        'field' => $i + 1, // 1-based
                        'old'   => $aVal,
                        'new'   => $bVal,
                    ];
                }
            }
        }

        // Typed result diff
        $aResultMap = $a->getResultMap();
        $bResultMap = $b->getResultMap();

        foreach ($aResultMap as $test => $aData) {
            $bData = $bResultMap[$test] ?? null;
            if ($bData === null) {
                continue; // test removed — captured in addedTypes/removedTypes
            }
            if ($aData !== $bData) {
                $resultChanges[] = [
                    'test' => $test,
                    'old'  => $aData,
                    'new'  => $bData,
                ];
            }
        }

        return new static($fieldChanges, $resultChanges, $addedTypes, $removedTypes);
    }

    // -----------------------------------------------------------------------
    //  Query
    // -----------------------------------------------------------------------

    public function hasDifferences(): bool
    {
        return !empty($this->fieldChanges)
            || !empty($this->addedTypes)
            || !empty($this->removedTypes);
    }

    /**
     * All field-level changes across all record types.
     *
     * @return list<array{type:string,seq:string,field:int,old:string,new:string}>
     */
    public function getFieldChanges(): array
    {
        return $this->fieldChanges;
    }

    /**
     * Result-level changes (only results present in both messages).
     *
     * @return list<array{test:string,old:array<string,string>,new:array<string,string>}>
     */
    public function getChangedResults(): array
    {
        return $this->resultChanges;
    }

    /**
     * Record types present in $b but not $a.
     *
     * @return list<string>
     */
    public function getAddedTypes(): array
    {
        return $this->addedTypes;
    }

    /**
     * Record types present in $a but not $b.
     *
     * @return list<string>
     */
    public function getRemovedTypes(): array
    {
        return $this->removedTypes;
    }

    /**
     * Tests whose value changed between the two messages.
     *
     * @return list<string>
     */
    public function getChangedTestNames(): array
    {
        return array_column($this->resultChanges, 'test');
    }

    /**
     * Human-readable summary of all differences.
     *
     * @return list<string>
     */
    public function getSummary(): array
    {
        $lines = [];

        foreach ($this->addedTypes as $t) {
            $lines[] = "Record type '{$t}' added.";
        }
        foreach ($this->removedTypes as $t) {
            $lines[] = "Record type '{$t}' removed.";
        }
        foreach ($this->fieldChanges as $c) {
            $lines[] = sprintf(
                '%s[%s] field %d: "%s" → "%s"',
                $c['type'], $c['seq'], $c['field'], $c['old'], $c['new']
            );
        }

        return $lines;
    }

    // -----------------------------------------------------------------------
    //  Internal
    // -----------------------------------------------------------------------

    /** @return array<string, AbstractRecord> */
    private static function indexRecords(Message $message): array
    {
        $index = [];
        foreach ($message->getRecords() as $record) {
            $key           = $record->getType() . ':' . $record->getField(2);
            $index[$key]   = $record;
        }
        return $index;
    }
}
