<?php

declare(strict_types=1);

namespace Astm;

use Astm\Records\Query;

/**
 * Fluent builder for {@see Query} records.
 * Obtained via {@see MessageBuilder::query()}.
 */
final class QueryBuilder
{
    private Query $record;

    public function __construct(Delimiters $d, int $seq)
    {
        $this->record = new Query($d);
        $this->record->setField(1, Query::TYPE);
        $this->record->setField(2, (string) $seq);
    }

    public function startingId(string $id): static
    {
        $this->record->setField(3, $id);
        return $this;
    }

    public function endingId(string $id): static
    {
        $this->record->setField(4, $id);
        return $this;
    }

    public function testId(string $id): static
    {
        $this->record->setField(5, $id);
        return $this;
    }

    public function statusCodes(string $codes): static
    {
        $this->record->setField(13, $codes);
        return $this;
    }

    public function build(): Query
    {
        return $this->record;
    }
}
