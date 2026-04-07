<?php

declare(strict_types=1);

namespace Astm;

use Astm\Records\Patient;

/**
 * Fluent builder for {@see Patient} records.
 * Obtained via {@see MessageBuilder::patient()}.
 */
final class PatientBuilder
{
    private Patient $record;

    public function __construct(Delimiters $d, int $seq)
    {
        $this->record = new Patient($d);
        $this->record->setField(1, Patient::TYPE);
        $this->record->setField(2, (string) $seq);
    }

    /** Practice and lab patient IDs (fields 3 and 4). */
    public function id(string $practiceId, string $labId = ''): static
    {
        $this->record->setField(3, $practiceId);
        if ($labId !== '') {
            $this->record->setField(4, $labId);
        }
        return $this;
    }

    /** Patient name stored as last^first^middle in field 6. */
    public function name(string $last, string $first = '', string $middle = ''): static
    {
        $parts = array_filter([$last, $first, $middle], fn($v) => $v !== '');
        $this->record->setField(6, implode('^', $parts));
        return $this;
    }

    /** Birthdate as YYYYMMDD. */
    public function birthdate(string $yyyymmdd): static
    {
        $this->record->setField(8, $yyyymmdd);
        return $this;
    }

    /** M, F or U. */
    public function sex(string $sex): static
    {
        $this->record->setField(9, strtoupper($sex[0] ?? 'U'));
        return $this;
    }

    public function address(string $address): static
    {
        $this->record->setField(11, $address);
        return $this;
    }

    public function phone(string $phone): static
    {
        $this->record->setField(13, $phone);
        return $this;
    }

    public function physician(string $id): static
    {
        $this->record->setField(14, $id);
        return $this;
    }

    public function build(): Patient
    {
        return $this->record;
    }
}
