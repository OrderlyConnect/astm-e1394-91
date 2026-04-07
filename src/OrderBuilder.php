<?php

declare(strict_types=1);

namespace Astm;

use Astm\Records\Order;

/**
 * Fluent builder for {@see Order} records.
 * Obtained via {@see MessageBuilder::order()}.
 */
final class OrderBuilder
{
    private Order $record;
    /** @var list<string> */
    private array $testIds = [];

    public function __construct(private readonly Delimiters $d, int $seq)
    {
        $this->record = new Order($d);
        $this->record->setField(1, Order::TYPE);
        $this->record->setField(2, (string) $seq);
    }

    public function specimenId(string $id): static
    {
        $this->record->setField(3, $id);
        return $this;
    }

    public function instrumentSpecimenId(string $id): static
    {
        $this->record->setField(4, $id);
        return $this;
    }

    /**
     * Append a test to field 5 using the common "^^^^NAME^subId" pattern.
     * Multiple calls produce a repeat-delimited list.
     */
    public function addTest(string $testName, string $subId = ''): static
    {
        $entry = $this->d->component . $this->d->component
               . $this->d->component . $this->d->component
               . $testName
               . ($subId !== '' ? $this->d->component . $subId : '');

        $this->testIds[] = $entry;
        return $this;
    }

    /** A = add, C = cancel, N = new, etc. */
    public function actionCode(string $code): static
    {
        $this->record->setField(12, $code);
        return $this;
    }

    /** Requested date-time as YYYYMMDDHHMMSS. */
    public function requestedDateTime(string $dt): static
    {
        $this->record->setField(7, $dt);
        return $this;
    }

    /** Collection date-time as YYYYMMDDHHMMSS. */
    public function collectionDateTime(string $dt): static
    {
        $this->record->setField(8, $dt);
        return $this;
    }

    public function priority(string $p): static
    {
        $this->record->setField(6, $p);
        return $this;
    }

    /** N = normal, F = final, etc. */
    public function reportType(string $type): static
    {
        $this->record->setField(26, $type);
        return $this;
    }

    public function build(): Order
    {
        if (!empty($this->testIds)) {
            $this->record->setField(5, implode($this->d->repeat, $this->testIds));
        }
        return $this->record;
    }
}
