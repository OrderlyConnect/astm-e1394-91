<?php

declare(strict_types=1);

namespace Astm;

use Astm\Records\Result;

/**
 * Fluent builder for {@see Result} records.
 * Obtained via {@see MessageBuilder::result()}.
 */
final class ResultBuilder
{
    private Result $record;

    public function __construct(Delimiters $d, int $seq)
    {
        $this->record = new Result($d);
        $this->record->setField(1, Result::TYPE);
        $this->record->setField(2, (string) $seq);
    }

    /**
     * Set the Universal Test ID using the common ^^^^NAME^subId pattern.
     */
    public function test(string $name, string $subId = '1'): static
    {
        $this->record->setField(3, '^^^^' . $name . '^' . $subId);
        return $this;
    }

    /**
     * Set a fully custom Universal Test ID (raw field string).
     */
    public function universalTestId(string $raw): static
    {
        $this->record->setField(3, $raw);
        return $this;
    }

    public function value(string $value): static
    {
        $this->record->setField(4, $value);
        return $this;
    }

    public function units(string $units): static
    {
        $this->record->setField(5, $units);
        return $this;
    }

    public function referenceRange(string $range): static
    {
        $this->record->setField(6, $range);
        return $this;
    }

    /**
     * Abnormal flag.  Use Result::FLAG_* constants.
     */
    public function flag(string $flag): static
    {
        $this->record->setField(7, $flag);
        return $this;
    }

    /**
     * Result status.  Use Result::STATUS_* constants.  Defaults to 'F' (final).
     */
    public function status(string $status = Result::STATUS_FINAL): static
    {
        $this->record->setField(9, $status);
        return $this;
    }

    /** Instrument / operator identification (field 11). */
    public function instrument(string $id): static
    {
        $this->record->setField(14, $id);
        return $this;
    }

    /** Date-time test completed (YYYYMMDDHHMMSS). */
    public function completedAt(string $yyyymmddhhmmss): static
    {
        $this->record->setField(13, $yyyymmddhhmmss);
        return $this;
    }


    /**
     * Set the reference range from explicit low and high values.
     *
     * Produces the common "low-high" format, e.g. "4.0-11.0".
     * Either bound may be null to produce open-ended ranges like "-11.0" or "4.0-".
     */
    public function referenceRangeFromBounds(
        float|int|string|null $low,
        float|int|string|null $high,
        string $separator = '-',
    ): static {
        $range = ($low !== null ? (string) $low : '')
               . $separator
               . ($high !== null ? (string) $high : '');
        return $this->referenceRange($range);
    }

    /**
     * Set the reference range as a single limit (e.g. "<3.5" or ">0.1").
     */
    public function referenceRangeLimit(string $operator, float|int|string $limit): static
    {
        return $this->referenceRange($operator . $limit);
    }

    public function build(): Result
    {
        if ($this->record->getField(9) === '') {
            $this->record->setField(9, Result::STATUS_FINAL);
        }
        return $this->record;
    }
}
