<?php

declare(strict_types=1);

namespace Astm\Records;

/**
 * Test Order Record  –  O
 *
 * Field layout (E1394-97 §7.3):
 *   1  Record Type ID          (O)
 *   2  Sequence Number
 *   3  Specimen ID             [comp: id ^ collector ^ collector_suffix ^ special_1 ^ special_2]
 *   4  Instrument Specimen ID
 *   5  Universal Test ID       (repeat-delimited list; each is comp: id ^ name ^ type ^ mfr)
 *   6  Priority
 *   7  Requested / Ordered Date-Time
 *   8  Specimen Collection Date-Time
 *   9  Collection End Time
 *  10  Collection Volume
 *  11  Collector ID
 *  12  Action Code
 *  13  Danger Code
 *  14  Relevant Clinical Info
 *  15  Date / Time Specimen Received
 *  16  Specimen Type
 *  17  Ordering Physician
 *  18  Physician's Telephone Number
 *  19  User Field 1
 *  20  User Field 2
 *  21  Laboratory Field 1
 *  22  Laboratory Field 2
 *  23  Date-Time Results Reported / Last Modified
 *  24  Instrument Charge to CIS
 *  25  Instrument Section ID
 *  26  Report Type
 *  27  Reserved
 *  28  Location of Specimen Collection
 *  29  Nosocomial Infection Flag
 *  30  Specimen Service
 *  31  Specimen Institution
 */
final class Order extends AbstractRecord
{
    public const TYPE = 'O';

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getSequenceNumber(): string
    {
        return $this->getField(2);
    }

    /** Raw specimen ID field. */
    public function getSpecimenIdRaw(): string
    {
        return $this->getField(3);
    }

    /** Primary specimen ID (component 1 of field 3). */
    public function getSpecimenId(): string
    {
        return $this->getComponent(3, 1);
    }

    /** Instrument specimen ID (field 4). */
    public function getInstrumentSpecimenId(): string
    {
        return $this->getField(4);
    }

    /**
     * Return all requested tests as an array of raw component strings.
     * Field 5 may contain repeat-delimited test IDs.
     *
     * @return list<string>  Each element is a raw "id^name^type^mfr" string.
     */
    public function getUniversalTestIds(): array
    {
        return $this->getRepeats(5);
    }

    /**
     * Return all test names from the Universal Test ID field.
     * Handles the common "^^^^NAME" pattern (first non-empty component wins).
     *
     * @return list<string>
     */
    public function getTestNames(): array
    {
        $names = [];
        foreach ($this->getUniversalTestIds() as $raw) {
            foreach (explode($this->delimiters->component, $raw) as $part) {
                if ($part !== '') { $names[] = $part; break; }
            }
        }
        return array_values($names);
    }

    public function getPriority(): string
    {
        return $this->getField(6);
    }

    /** Requested / ordered date-time (YYYYMMDDHHMMSS). */
    public function getRequestedDateTime(): string
    {
        return $this->getField(7);
    }

    /** Specimen collection date-time (YYYYMMDDHHMMSS). */
    public function getCollectionDateTime(): string
    {
        return $this->getField(8);
    }

    public function getActionCode(): string
    {
        return $this->getField(12);
    }

    public function getReportType(): string
    {
        return $this->getField(26);
    }
}
