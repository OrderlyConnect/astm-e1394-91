<?php

declare(strict_types=1);

namespace Astm\Records;

/**
 * Result Record  –  R
 *
 * Field layout (E1394-97 §7.4):
 *   1  Record Type ID          (R)
 *   2  Sequence Number
 *   3  Universal Test ID       [comp: manufacturer ^ instrument ^ test_id ^ test_name ^ dilution_factor ^ status ^ reserved]
 *   4  Data or Measurement Value
 *   5  Units
 *   6  Reference Ranges
 *   7  Result Abnormal Flags   (H / L / N / A / W …)
 *   8  Nature of Abnormality Testing
 *   9  Result Status           (C / F / I / P / S / X)
 *  10  Date of Change in Instrument Normative Values
 *  11  Operator Identification
 *  12  Date-Time Test Started
 *  13  Date-Time Test Completed
 *  14  Instrument Identification
 *
 * The "Universal Test ID" in field 3 typically arrives as four leading carets
 * (^^^^) followed by the test name, e.g.  ^^^^WBC^1  →  components 5=WBC, 6=1.
 */
final class Result extends AbstractRecord
{
    public const TYPE = 'R';

    // Abnormal-flag constants -----------------------------------------------
    public const FLAG_NORMAL       = 'N';
    public const FLAG_ABOVE_NORMAL = 'H';   // High
    public const FLAG_BELOW_NORMAL = 'L';   // Low
    public const FLAG_ABNORMAL     = 'A';   // Abnormal (qualitative)
    public const FLAG_CRITICAL_H   = 'HH';  // Critical high
    public const FLAG_CRITICAL_L   = 'LL';  // Critical low
    public const FLAG_WARNING      = 'W';   // Warning

    // Result-status constants -----------------------------------------------
    public const STATUS_FINAL      = 'F';
    public const STATUS_CORRECTION = 'C';
    public const STATUS_PRELIMINARY= 'P';
    public const STATUS_INCOMPLETE = 'I';

    // -----------------------------------------------------------------------

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getSequenceNumber(): string
    {
        return $this->getField(2);
    }

    /** Full raw test-ID field (e.g. "^^^^WBC^1"). */
    public function getUniversalTestIdRaw(): string
    {
        return $this->getField(3);
    }

    /**
     * Parse the Universal Test ID field into its components.
     *
     * ASTM §7.4.3 defines six components; instruments often leave the first
     * four blank, so a value of "^^^^WBC^1" yields:
     *   [0] ''  manufacturer code
     *   [1] ''  instrument code
     *   [2] ''  test code
     *   [3] ''  test code 2
     *   [4] 'WBC' test name
     *   [5] '1'   dilution factor / sub-id
     *
     * @return list<string>
     */
    public function getUniversalTestIdComponents(): array
    {
        return explode($this->delimiters->component, $this->getUniversalTestIdRaw());
    }

    /**
     * Convenience: return the test name from the Universal Test ID.
     * Handles the common "^^^^NAME" pattern by returning the first non-empty component.
     */
    public function getTestName(): string
    {
        foreach ($this->getUniversalTestIdComponents() as $part) {
            if ($part !== '') {
                return $part;
            }
        }
        return '';
    }

    /** Numeric or string result value. */
    public function getValue(): string
    {
        return $this->getField(4);
    }

    /** Attempt to return the value as a float; returns null when not numeric. */
    public function getNumericValue(): ?float
    {
        $v = $this->getValue();
        return is_numeric($v) ? (float) $v : null;
    }

    /** Units string (e.g. "10*3/uL", "g/dL", "%"). */
    public function getUnits(): string
    {
        return $this->getField(5);
    }

    /** Reference range string as reported by the instrument. */
    public function getReferenceRange(): string
    {
        return $this->getField(6);
    }

    /**
     * Abnormal flag(s).  May be a single code (N, H, L, A, W) or empty.
     * See FLAG_* constants.
     */
    public function getAbnormalFlag(): string
    {
        return $this->getField(7);
    }

    public function isNormal(): bool
    {
        return $this->getAbnormalFlag() === self::FLAG_NORMAL;
    }

    public function isAbnormal(): bool
    {
        return !in_array($this->getAbnormalFlag(), ['', self::FLAG_NORMAL], true);
    }

    public function isHigh(): bool
    {
        return str_starts_with($this->getAbnormalFlag(), 'H');
    }

    public function isLow(): bool
    {
        return str_starts_with($this->getAbnormalFlag(), 'L');
    }

    /**
     * Result status.  'F' = Final, 'C' = Correction, etc.
     * See STATUS_* constants.
     */
    public function getResultStatus(): string
    {
        return $this->getField(9);
    }

    public function isFinal(): bool
    {
        return $this->getResultStatus() === self::STATUS_FINAL;
    }

    /** Date-Time Test Completed (YYYYMMDDHHMMSS). */
    public function getCompletedDateTime(): string
    {
        return $this->getField(13);
    }

    public function getCompletedDateTimeObject(): ?\DateTimeImmutable
    {
        return \Astm\DateTimeHelper::parse($this->getCompletedDateTime());
    }
}
