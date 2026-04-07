<?php

declare(strict_types=1);

namespace Astm\Records;

use Astm\Delimiters;

/**
 * Message Header Record  –  H
 *
 * Field layout (E1394-97 §7.1):
 *   1  Record Type ID         (H)
 *   2  Delimiter Definition   (\^&)
 *   3  Message Control ID
 *   4  Access Password
 *   5  Sender Name/ID         [comp: name ^ id ^ version ^ serial ^ config]
 *   6  Sender Street Address
 *   7  Reserved
 *   8  Sender Telephone Number
 *   9  Sender Characteristics
 *  10  Receiver ID
 *  11  Comments
 *  12  Processing ID
 *  13  Version Number
 *  14  Date and Time of Message
 */
final class Header extends AbstractRecord
{
    public const TYPE = 'H';

    // -----------------------------------------------------------------------
    //  Typed accessors
    // -----------------------------------------------------------------------

    public function getType(): string
    {
        return self::TYPE;
    }

    /** Delimiter definition string, e.g. \^& */
    public function getDelimiterDefinition(): string
    {
        return $this->getField(2);
    }

    /** Full sender name/ID field (pipe-encoded). */
    public function getSenderNameRaw(): string
    {
        return $this->getField(5);
    }

    /** Sender instrument name (component 1 of field 5), whitespace-trimmed. */
    public function getSenderName(): string
    {
        return trim($this->getComponent(5, 1));
    }

    /** Sender instrument ID (component 2 of field 5). */
    public function getSenderId(): string
    {
        return $this->getComponent(5, 2);
    }

    /** Sender software version (component 3 of field 5). */
    public function getSenderVersion(): string
    {
        return $this->getComponent(5, 3);
    }

    public function getProcessingId(): string
    {
        return $this->getField(12);
    }

    /** ASTM version, e.g. "E1394-97". */
    public function getVersionNumber(): string
    {
        return $this->getField(13);
    }

    /** Raw timestamp string (YYYYMMDDHHMMSS). */
    public function getMessageDateTime(): string
    {
        return $this->getField(14);
    }

    /** Parse the message timestamp into a DateTimeImmutable. */
    public function getMessageDateTimeObject(): ?\DateTimeImmutable
    {
        return \Astm\DateTimeHelper::parse($this->getMessageDateTime());
    }
}
