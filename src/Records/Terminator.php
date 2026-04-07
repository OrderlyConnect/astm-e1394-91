<?php

declare(strict_types=1);

namespace Astm\Records;

/**
 * Message Terminator Record  –  L
 *
 * Field layout (E1394-97 §7.7):
 *   1  Record Type ID      (L)
 *   2  Sequence Number
 *   3  Termination Code    (N = normal)
 */
final class Terminator extends AbstractRecord
{
    public const TYPE = 'L';

    public function getType(): string { return self::TYPE; }

    public function getSequenceNumber(): string  { return $this->getField(2); }
    public function getTerminationCode(): string { return $this->getField(3); }

    public function isNormalTermination(): bool
    {
        $code = $this->getTerminationCode();
        return $code === '' || $code === 'N';
    }
}
