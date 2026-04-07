<?php

declare(strict_types=1);

namespace Astm\Exceptions;

class UnknownRecordTypeException extends ParseException
{
    public function __construct(string $type)
    {
        parent::__construct("Unknown ASTM record type: '{$type}'");
    }
}
