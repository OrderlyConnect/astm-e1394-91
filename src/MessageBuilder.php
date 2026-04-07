<?php

declare(strict_types=1);

namespace Astm;

use Astm\Records\Comment;
use Astm\Records\Header;
use Astm\Records\Order;
use Astm\Records\Patient;
use Astm\Records\Query;
use Astm\Records\Result;
use Astm\Records\Terminator;

/**
 * Fluent builder for constructing ASTM E1394-97 {@see Message} objects.
 *
 * Every method returns `$this` so calls chain freely.
 * Sub-records are configured via a closure that receives a typed builder:
 *
 *   $message = MessageBuilder::create()
 *       ->sender('MY-LIS', 'v2.1')
 *       ->patient(fn(PatientBuilder $p) => $p->id('P001')->name('Doe','Jane')->sex('F'))
 *       ->order(fn(OrderBuilder $o) => $o->specimenId('S-99')->addTest('WBC')->addTest('RBC'))
 *       ->result(fn(ResultBuilder $r) => $r->test('WBC')->value('7.2')->units('10*3/uL')->flag('N'))
 *       ->result(fn(ResultBuilder $r) => $r->test('RBC')->value('4.8')->units('10*6/uL')->flag('N'))
 *       ->comment('Slightly haemolysed')
 *       ->build();
 */
final class MessageBuilder
{
    private readonly Delimiters $delimiters;
    private readonly Message    $message;

    private int  $patientSeq  = 0;
    private int  $orderSeq    = 0;
    private int  $resultSeq   = 0;
    private int  $commentSeq  = 0;
    private int  $querySeq    = 0;
    private bool $terminated  = false;

    private function __construct(?Delimiters $delimiters = null)
    {
        $this->delimiters = $delimiters ?? new Delimiters();
        $this->message    = new Message();
    }

    // -----------------------------------------------------------------------
    //  Factory
    // -----------------------------------------------------------------------

    public static function create(?Delimiters $delimiters = null): static
    {
        return new static($delimiters);
    }

    // -----------------------------------------------------------------------
    //  Header  (H)
    // -----------------------------------------------------------------------

    /**
     * @param string $senderName    Instrument / LIS name.
     * @param string $senderVersion Optional software version string.
     * @param string $processingId  'P' = production, 'T' = training, 'D' = debugging.
     * @param string $receiverId    Receiving system identifier.
     */
    public function sender(
        string $senderName    = '',
        string $senderVersion = '',
        string $processingId  = 'P',
        string $receiverId    = '',
    ): static {
        $d = $this->delimiters;
        $h = new Header($d);

        $h->setField(1,  Header::TYPE);
        $h->setField(2,  $d->repeat . $d->component . $d->escape);
        $h->setField(5,  $senderName . $d->component . $d->component . $senderVersion);
        $h->setField(10, $receiverId);
        $h->setField(12, $processingId);
        $h->setField(13, 'E1394-97');
        $h->setField(14, (new \DateTimeImmutable())->format('YmdHis'));

        $this->message->addRecord($h);
        return $this;
    }


    // -----------------------------------------------------------------------
    //  Header  (H) — fine-grained builder
    // -----------------------------------------------------------------------

    /**
     * Configure the H record using a full {@see HeaderBuilder} for fine-grained
     * control over every field.  For most cases {@see sender()} is sufficient.
     *
     *   $message = MessageBuilder::create()
     *       ->header(fn(HeaderBuilder $h) => $h
     *           ->senderName('XN-350')
     *           ->senderId('00-14')
     *           ->senderVersion('3.1.2')
     *           ->serialNumber('SN12345')
     *           ->receiverId('MY-LIS')
     *           ->processingId('P'))
     *       ->result(...)
     *       ->build();
     *
     * @param callable(HeaderBuilder): mixed $configure
     */
    public function header(callable $configure): static
    {
        $hb = new HeaderBuilder($this->delimiters);
        $configure($hb);
        $this->message->addRecord($hb->build());
        return $this;
    }

    // -----------------------------------------------------------------------
    //  Patient  (P)
    // -----------------------------------------------------------------------

    /** @param callable(PatientBuilder): mixed $configure */
    public function patient(callable $configure): static
    {
        $pb = new PatientBuilder($this->delimiters, ++$this->patientSeq);
        $configure($pb);
        $this->message->addRecord($pb->build());
        return $this;
    }

    // -----------------------------------------------------------------------
    //  Order  (O)
    // -----------------------------------------------------------------------

    /** @param callable(OrderBuilder): mixed $configure */
    public function order(callable $configure): static
    {
        $ob = new OrderBuilder($this->delimiters, ++$this->orderSeq);
        $configure($ob);
        $this->message->addRecord($ob->build());
        return $this;
    }

    // -----------------------------------------------------------------------
    //  Result  (R)
    // -----------------------------------------------------------------------

    /** @param callable(ResultBuilder): mixed $configure */
    public function result(callable $configure): static
    {
        $rb = new ResultBuilder($this->delimiters, ++$this->resultSeq);
        $configure($rb);
        $this->message->addRecord($rb->build());
        return $this;
    }

    // -----------------------------------------------------------------------
    //  Comment  (C)
    // -----------------------------------------------------------------------

    /** @param string $type G = generic, I = instrument. */
    public function comment(string $text, string $source = '', string $type = 'G'): static
    {
        $c = new Comment($this->delimiters);
        $c->setField(1, Comment::TYPE);
        $c->setField(2, (string) ++$this->commentSeq);
        $c->setField(3, $source);
        $c->setField(4, $text);
        $c->setField(5, $type);
        $this->message->addRecord($c);
        return $this;
    }

    // -----------------------------------------------------------------------
    //  Query  (Q)
    // -----------------------------------------------------------------------

    /** @param callable(QueryBuilder): mixed $configure */
    public function query(callable $configure): static
    {
        $qb = new QueryBuilder($this->delimiters, ++$this->querySeq);
        $configure($qb);
        $this->message->addRecord($qb->build());
        return $this;
    }

    // -----------------------------------------------------------------------
    //  Build
    // -----------------------------------------------------------------------

    /**
     * Append the L terminator record and return the completed Message.
     * Idempotent — safe to call multiple times.
     */
    public function build(): Message
    {
        if (!$this->terminated) {
            $l = new Terminator($this->delimiters);
            $l->setField(1, Terminator::TYPE);
            $l->setField(2, '1');
            $l->setField(3, 'N');
            $this->message->addRecord($l);
            $this->terminated = true;
        }
        return $this->message;
    }

    // -----------------------------------------------------------------------
    //  Named constructor — clone an existing parsed Message
    // -----------------------------------------------------------------------

    /**
     * Create a builder pre-loaded with all records from an existing Message.
     *
     * Sequence counters are initialised from the existing records so that
     * records you append via the fluent API continue from the correct numbers.
     *
     *   $enriched = MessageBuilder::fromMessage($received)
     *       ->comment('Verified by LIS')
     *       ->build();
     */
    public static function fromMessage(Message $message, ?Delimiters $delimiters = null): static
    {
        $builder = new static($delimiters);

        foreach ($message->getRecords() as $record) {
            // Skip the L record — build() appends a fresh one.
            if ($record->getType() === Terminator::TYPE) {
                continue;
            }
            $builder->message->addRecord($record);

            // Keep sequence counters in sync
            $seq = (int) $record->getField(2);
            match ($record->getType()) {
                Patient::TYPE => $builder->patientSeq = max($builder->patientSeq, $seq),
                Order::TYPE   => $builder->orderSeq   = max($builder->orderSeq, $seq),
                Result::TYPE  => $builder->resultSeq  = max($builder->resultSeq, $seq),
                Comment::TYPE => $builder->commentSeq = max($builder->commentSeq, $seq),
                Query::TYPE   => $builder->querySeq   = max($builder->querySeq, $seq),
                default               => null,
            };
        }

        return $builder;
    }
}
