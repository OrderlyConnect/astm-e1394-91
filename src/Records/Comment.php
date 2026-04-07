<?php

declare(strict_types=1);

namespace Astm\Records;

/**
 * Comment Record  –  C
 *
 * Field layout (E1394-97 §7.5):
 *   1  Record Type ID  (C)
 *   2  Sequence Number
 *   3  Comment Source
 *   4  Comment Text
 *   5  Comment Type
 */
final class Comment extends AbstractRecord
{
    public const TYPE = 'C';

    public function getType(): string
    {
        return self::TYPE;
    }

    public function getSequenceNumber(): string
    {
        return $this->getField(2);
    }

    public function getCommentSource(): string
    {
        return $this->getField(3);
    }

    public function getCommentText(): string
    {
        return $this->getField(4);
    }

    public function getCommentType(): string
    {
        return $this->getField(5);
    }
}
