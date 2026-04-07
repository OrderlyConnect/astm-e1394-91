<?php

declare(strict_types=1);

namespace Astm;

use Astm\Records\Header;

/**
 * Fluent builder for {@see Header} records.
 *
 * Used internally by {@see MessageBuilder::header()} when you need fine-grained
 * control over every H-record field.  For simple cases, use the shorthand
 * {@see MessageBuilder::sender()} instead.
 *
 * Field layout (E1394-97 §7.1):
 *  1  Record Type         (H)
 *  2  Delimiter Encoding  (\^& with default delimiters)
 *  3  Message Control ID
 *  4  Access Password
 *  5  Sender Name / ID    [comp: name ^ id ^ version ^ serial ^ config]
 *  6  Sender Address
 *  7  Reserved
 *  8  Sender Telephone
 *  9  Sender Characteristics
 * 10  Receiver ID
 * 11  Comment
 * 12  Processing ID        P=production T=training D=debug
 * 13  Version Number       E1394-97
 * 14  Date-Time of Message YYYYMMDDHHMMSS
 */
final class HeaderBuilder
{
    private Header $record;

    public function __construct(private readonly Delimiters $d)
    {
        $this->record = new Header($d);
        $this->record->setField(1,  Header::TYPE);
        $this->record->setField(2,  $d->repeat . $d->component . $d->escape);
        $this->record->setField(12, 'P');
        $this->record->setField(13, 'E1394-97');
        $this->record->setField(14, (new \DateTimeImmutable())->format('YmdHis'));
    }

    // -----------------------------------------------------------------------
    //  Sender identification (field 5 components)
    // -----------------------------------------------------------------------

    /**
     * Set all sender identification components at once.
     *
     * @param string $name     Instrument / LIS name (component 1).
     * @param string $id       System / station ID   (component 2).
     * @param string $version  Software version      (component 3).
     * @param string $serial   Serial number         (component 4).
     * @param string $config   Configuration string  (component 5).
     */
    public function sender(
        string $name    = '',
        string $id      = '',
        string $version = '',
        string $serial  = '',
        string $config  = '',
    ): static {
        $c = $this->d->component;
        $this->record->setField(5, "{$name}{$c}{$id}{$c}{$version}{$c}{$serial}{$c}{$config}");
        return $this;
    }

    /** Shorthand — set just the instrument/LIS name. */
    public function senderName(string $name): static
    {
        $components       = explode($this->d->component, $this->record->getField(5));
        $components[0]    = $name;
        $this->record->setField(5, implode($this->d->component, $components));
        return $this;
    }

    /** Shorthand — set just the sender ID. */
    public function senderId(string $id): static
    {
        $components       = explode($this->d->component, $this->record->getField(5));
        $components[1]    = $id;
        $this->record->setField(5, implode($this->d->component, $components));
        return $this;
    }

    /** Shorthand — set just the sender software version. */
    public function senderVersion(string $version): static
    {
        $components       = explode($this->d->component, $this->record->getField(5));
        $components[2]    = $version;
        $this->record->setField(5, implode($this->d->component, $components));
        return $this;
    }

    /** Shorthand — set just the serial number. */
    public function serialNumber(string $serial): static
    {
        $components       = explode($this->d->component, $this->record->getField(5));
        $components[3]    = $serial;
        $this->record->setField(5, implode($this->d->component, $components));
        return $this;
    }

    // -----------------------------------------------------------------------
    //  Other H-record fields
    // -----------------------------------------------------------------------

    public function messageControlId(string $id): static
    {
        $this->record->setField(3, $id);
        return $this;
    }

    public function accessPassword(string $password): static
    {
        $this->record->setField(4, $password);
        return $this;
    }

    public function senderAddress(string $address): static
    {
        $this->record->setField(6, $address);
        return $this;
    }

    public function senderPhone(string $phone): static
    {
        $this->record->setField(8, $phone);
        return $this;
    }

    public function receiverId(string $id): static
    {
        $this->record->setField(10, $id);
        return $this;
    }

    public function comment(string $comment): static
    {
        $this->record->setField(11, $comment);
        return $this;
    }

    /**
     * Processing ID: 'P' = production, 'T' = training, 'D' = debug.
     */
    public function processingId(string $id): static
    {
        $this->record->setField(12, $id);
        return $this;
    }

    /**
     * Override the message timestamp (default = current UTC time).
     * Format: YYYYMMDDHHMMSS.
     */
    public function messageDateTime(string $yyyymmddhhmmss): static
    {
        $this->record->setField(14, $yyyymmddhhmmss);
        return $this;
    }

    /** Set the timestamp from a DateTimeImmutable. */
    public function messageDateTimeObject(\DateTimeImmutable $dt): static
    {
        return $this->messageDateTime($dt->format('YmdHis'));
    }

    // -----------------------------------------------------------------------
    //  Build
    // -----------------------------------------------------------------------

    public function build(): Header
    {
        return $this->record;
    }
}
