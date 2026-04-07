<?php

declare(strict_types=1);

namespace Astm\Records;

use Astm\Delimiters;

/**
 * Base class for all ASTM record types.
 *
 * An ASTM record is a single line (terminated by CR in the wire format) composed
 * of pipe-delimited fields.  Field[0] is always the one-letter record-type ID.
 *
 * Field numbering follows the ASTM spec (1-based), so $record->getField(1) returns
 * the record-type character, $record->getField(2) the second field, etc.
 * Internally fields are stored in a 0-based array for simplicity.
 */
abstract class AbstractRecord
{
    /** @var list<string> Raw (un-decoded) field strings. */
    protected array $fields = [];

    public function __construct(protected readonly Delimiters $delimiters) {}

    // -----------------------------------------------------------------------
    //  Factory
    // -----------------------------------------------------------------------

    /**
     * Populate this record from a raw line (without the trailing CR/LF).
     */
    public function fromLine(string $line): static
    {
        $this->fields = explode($this->delimiters->field, $line);
        return $this;
    }

    // -----------------------------------------------------------------------
    //  Field access  (1-based, matching the ASTM spec field numbers)
    // -----------------------------------------------------------------------

    /**
     * Return the raw string of the nth field (1-based).
     * Returns an empty string when the field does not exist.
     */
    public function getField(int $n): string
    {
        return $this->fields[$n - 1] ?? '';
    }

    /**
     * Set the nth field (1-based).  Fills missing intermediate fields with ''.
     */
    public function setField(int $n, string $value): static
    {
        $index = $n - 1;
        while (count($this->fields) <= $index) {
            $this->fields[] = '';
        }
        $this->fields[$index] = $value;
        return $this;
    }

    /**
     * Split the nth field by the component delimiter and return the components.
     * Components are returned 1-based (index 1 = first component).
     *
     * @return list<string>
     */
    public function getComponents(int $fieldN): array
    {
        $raw = $this->getField($fieldN);
        return explode($this->delimiters->component, $raw);
    }

    /**
     * Return a single component of a field.
     * Both $fieldN and $componentN are 1-based.
     */
    public function getComponent(int $fieldN, int $componentN): string
    {
        return $this->getComponents($fieldN)[$componentN - 1] ?? '';
    }

    /**
     * Split the nth field by the repeat delimiter.
     *
     * @return list<string>
     */
    public function getRepeats(int $fieldN): array
    {
        $raw = $this->getField($fieldN);
        return explode($this->delimiters->repeat, $raw);
    }

    // -----------------------------------------------------------------------
    //  All fields
    // -----------------------------------------------------------------------

    /** @return list<string> */
    public function getFields(): array
    {
        return $this->fields;
    }

    // -----------------------------------------------------------------------
    //  Record type
    // -----------------------------------------------------------------------

    abstract public function getType(): string;


    /**
     * Return all fields as an indexed array (0-based internally, but returned 1-based keys).
     *
     * @return array<int, string>  Keys are 1-based field numbers.
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->fields as $i => $value) {
            $result[$i + 1] = $value;
        }
        return $result;
    }

    // -----------------------------------------------------------------------
    //  Serialisation
    // -----------------------------------------------------------------------

    /**
     * Render the record back to a pipe-delimited string (no line terminator).
     */
    public function toString(): string
    {
        return implode($this->delimiters->field, $this->fields);
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
