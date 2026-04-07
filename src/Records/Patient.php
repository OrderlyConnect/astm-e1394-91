<?php

declare(strict_types=1);

namespace Astm\Records;

/**
 * Patient Information Record  –  P
 *
 * Field layout (E1394-97 §7.2):
 *   1  Record Type ID         (P)
 *   2  Sequence Number
 *   3  Practice-Assigned Patient ID
 *   4  Laboratory-Assigned Patient ID
 *   5  Patient ID No. 3
 *   6  Patient Name           [comp: last ^ first ^ middle]
 *   7  Mother's Maiden Name
 *   8  Birthdate              (YYYYMMDD)
 *   9  Patient Sex            (M / F / U)
 *  10  Patient Race / Ethnic Origin
 *  11  Patient Address
 *  12  Reserved
 *  13  Patient Telephone Number
 *  14  Attending Physician ID
 *  15  Special Field 1
 *  16  Special Field 2
 *  17  Patient Height
 *  18  Patient Weight
 *  19  Patient's Known Diagnosis
 *  20  Patient's Active Medications
 *  21  Patient's Diet
 *  22  Practice Field No. 1
 *  23  Practice Field No. 2
 *  24  Admission / Discharge Dates
 *  25  Admission Status
 *  26  Location
 *  27  Nature of Alternative Diagnostic Code and Classif.
 *  28  Alternative Diagnostic Code and Classification
 *  29  Patient Religion
 *  30  Marital Status
 *  31  Isolation Status
 *  32  Language
 *  33  Hospital Service
 *  34  Hospital Institution
 *  35  Dosage Category
 */
final class Patient extends AbstractRecord
{
    public const TYPE = 'P';

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getSequenceNumber(): string
    {
        return $this->getField(2);
    }

    public function getPracticePatientId(): string
    {
        return $this->getField(3);
    }

    public function getLabPatientId(): string
    {
        return $this->getField(4);
    }

    /** Full name field raw string (last^first^middle). */
    public function getPatientNameRaw(): string
    {
        return $this->getField(6);
    }

    public function getLastName(): string
    {
        return $this->getComponent(6, 1);
    }

    public function getFirstName(): string
    {
        return $this->getComponent(6, 2);
    }

    public function getMiddleName(): string
    {
        return $this->getComponent(6, 3);
    }

    /** Raw birthdate string (YYYYMMDD or YYYYMMDDHHMMSS). */
    public function getBirthdate(): string
    {
        return $this->getField(8);
    }

    public function getBirthdateObject(): ?\DateTimeImmutable
    {
        return \Astm\DateTimeHelper::parseDate($this->getBirthdate());
    }

    /** M, F, or U. */
    public function getSex(): string
    {
        return $this->getField(9);
    }

    public function getAddress(): string
    {
        return $this->getField(11);
    }

    public function getPhoneNumber(): string
    {
        return $this->getField(13);
    }

    public function getAttendingPhysicianId(): string
    {
        return $this->getField(14);
    }
}
