<?php

declare(strict_types=1);

namespace Astm\Records;

/**
 * Request Information Record  –  Q
 *
 * Field layout (E1394-97 §7.6):
 *   1  Record Type ID             (Q)
 *   2  Sequence Number
 *   3  Starting Range ID Number
 *   4  Ending Range ID Number
 *   5  Universal Test ID
 *   6  Nature of Request Time Limits
 *   7  Beginning Request Results Date and Time
 *   8  Ending Request Results Date and Time
 *   9  Requesting Physician Name
 *  10  Requesting Physician Telephone Number
 *  11  User Field No. 1
 *  12  User Field No. 2
 *  13  Request Information Status Codes
 */
final class Query extends AbstractRecord
{
    public const TYPE = 'Q';

    public function getType(): string { return self::TYPE; }

    public function getSequenceNumber(): string  { return $this->getField(2); }
    public function getStartingRangeId(): string { return $this->getField(3); }
    public function getEndingRangeId(): string   { return $this->getField(4); }
    public function getUniversalTestId(): string { return $this->getField(5); }
    public function getStatusCodes(): string     { return $this->getField(13); }
}
