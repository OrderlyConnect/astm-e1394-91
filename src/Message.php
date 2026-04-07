<?php

declare(strict_types=1);

namespace Astm;

use Astm\Records\AbstractRecord;
use Astm\Records\Comment;
use Astm\Records\Header;
use Astm\Records\Order;
use Astm\Records\Patient;
use Astm\Records\Result;
use Astm\Records\Terminator;

/**
 * Represents a complete ASTM E1394-97 message.
 *
 * A message is an ordered collection of records, always starting with an H
 * (Header) record and ending with an L (Terminator) record.
 */
final class Message
{
    /** @var list<AbstractRecord> */
    private array $records = [];

    // -----------------------------------------------------------------------
    //  Construction helpers
    // -----------------------------------------------------------------------

    public function addRecord(AbstractRecord $record): static
    {
        $this->records[] = $record;
        return $this;
    }

    // -----------------------------------------------------------------------
    //  Generic record access
    // -----------------------------------------------------------------------

    /** @return list<AbstractRecord> */
    public function getRecords(): array
    {
        return $this->records;
    }

    /**
     * Return all records of a given type identifier.
     *
     * @return list<AbstractRecord>
     */
    public function getRecordsByType(string $type): array
    {
        return array_values(
            array_filter($this->records, fn(AbstractRecord $r) => $r->getType() === $type)
        );
    }

    // -----------------------------------------------------------------------
    //  Typed accessors
    // -----------------------------------------------------------------------

    public function getHeader(): ?Header
    {
        /** @var Header|null */
        return $this->getRecordsByType(Header::TYPE)[0] ?? null;
    }

    /** @return list<Patient> */
    public function getPatients(): array
    {
        /** @var list<Patient> */
        return $this->getRecordsByType(Patient::TYPE);
    }

    public function getFirstPatient(): ?Patient
    {
        return $this->getPatients()[0] ?? null;
    }

    /** @return list<Order> */
    public function getOrders(): array
    {
        /** @var list<Order> */
        return $this->getRecordsByType(Order::TYPE);
    }

    public function getFirstOrder(): ?Order
    {
        return $this->getOrders()[0] ?? null;
    }

    /** @return list<Result> */
    public function getResults(): array
    {
        /** @var list<Result> */
        return $this->getRecordsByType(Result::TYPE);
    }

    /** @return list<Comment> */
    public function getComments(): array
    {
        /** @var list<Comment> */
        return $this->getRecordsByType(Comment::TYPE);
    }

    public function getTerminator(): ?Terminator
    {
        /** @var Terminator|null */
        return $this->getRecordsByType(Terminator::TYPE)[0] ?? null;
    }

    // -----------------------------------------------------------------------
    //  Convenience helpers

    /**
     * Results with a non-empty, non-normal abnormal flag.
     *
     * @return list<\Astm\Records\Result>
     */
    public function getAbnormalResults(): array
    {
        return array_values(array_filter($this->getResults(), fn(Records\Result $r) => $r->isAbnormal()));
    }

    /**
     * Results with status 'F' (Final).
     *
     * @return list<\Astm\Records\Result>
     */
    public function getFinalResults(): array
    {
        return array_values(array_filter($this->getResults(), fn(Records\Result $r) => $r->isFinal()));
    }

    /**
     * Whether this message contains any abnormal results.
     */
    public function hasAbnormalities(): bool
    {
        return !empty($this->getAbnormalResults());
    }


    // -----------------------------------------------------------------------

    /**
     * Return results as a flat associative array keyed by test name.
     *
     * [
     *   'WBC' => ['value' => '9.24', 'units' => '10*3/uL', 'flag' => 'N', 'status' => 'F'],
     *   ...
     * ]
     *
     * @return array<string, array{value:string, units:string, flag:string, status:string}>
     */
    public function getResultMap(): array
    {
        $map = [];
        foreach ($this->getResults() as $result) {
            $name = $result->getTestName();
            if ($name === '') {
                continue;
            }
            $map[$name] = [
                'value'  => $result->getValue(),
                'units'  => $result->getUnits(),
                'flag'   => $result->getAbnormalFlag(),
                'status' => $result->getResultStatus(),
            ];
        }
        return $map;
    }


    /**
     * Serialize the message to a nested PHP array.
     *
     * Shape:
     * [
     *   'sender'   => string,
     *   'version'  => string,
     *   'datetime' => string,
     *   'patient'  => ['id'=>..., 'name'=>..., 'sex'=>..., 'birthdate'=>...] | null,
     *   'order'    => ['specimenId'=>..., 'tests'=>[...]] | null,
     *   'results'  => [['test'=>..., 'value'=>..., 'units'=>..., 'flag'=>..., 'status'=>...], ...],
     *   'comments' => [string, ...],
     * ]
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $header  = $this->getHeader();
        $patient = $this->getFirstPatient();
        $order   = $this->getFirstOrder();

        return [
            'sender'   => $header?->getSenderName() ?? '',
            'version'  => $header?->getVersionNumber() ?? '',
            'datetime' => $header?->getMessageDateTime() ?? '',
            'patient'  => $patient === null ? null : [
                'practiceId' => $patient->getPracticePatientId(),
                'labId'      => $patient->getLabPatientId(),
                'lastName'   => $patient->getLastName(),
                'firstName'  => $patient->getFirstName(),
                'middleName' => $patient->getMiddleName(),
                'sex'        => $patient->getSex(),
                'birthdate'  => $patient->getBirthdate(),
                'address'    => $patient->getAddress(),
                'phone'      => $patient->getPhoneNumber(),
            ],
            'order'    => $order === null ? null : [
                'specimenId' => $order->getSpecimenId(),
                'tests'      => $order->getTestNames(),
                'priority'   => $order->getPriority(),
                'reportType' => $order->getReportType(),
                'collection' => $order->getCollectionDateTime(),
            ],
            'results'  => array_map(
                fn(Records\Result $r) => [
                    'test'      => $r->getTestName(),
                    'value'     => $r->getValue(),
                    'units'     => $r->getUnits(),
                    'reference' => $r->getReferenceRange(),
                    'flag'      => $r->getAbnormalFlag(),
                    'status'    => $r->getResultStatus(),
                    'completed' => $r->getCompletedDateTime(),
                ],
                $this->getResults()
            ),
            'comments' => array_map(
                fn(Records\Comment $c) => $c->getCommentText(),
                $this->getComments()
            ),
        ];
    }

    /**
     * Serialize to a JSON string.
     *
     * @throws \JsonException on encoding failure.
     */
    public function toJson(int $flags = JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT): string
    {
        return json_encode($this->toArray(), $flags);
    }

    // -----------------------------------------------------------------------
    //  Serialisation
    // -----------------------------------------------------------------------

    /**
     * Render the message back to a CR-delimited string, matching the ASTM
     * wire format (each record terminated by \r).
     */
    public function toString(string $lineEnding = "\r"): string
    {
        return implode(
            $lineEnding,
            array_map(fn(AbstractRecord $r) => $r->toString(), $this->records)
        ) . $lineEnding;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
