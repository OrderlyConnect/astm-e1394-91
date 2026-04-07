<?php

declare(strict_types=1);

namespace Astm\Tests;

use Astm\Delimiters;
use Astm\Message;
use Astm\MessageBuilder;
use Astm\Parser;
use Astm\Protocol\Ascii;
use Astm\Protocol\Frame;
use Astm\Protocol\LlpDecoder;
use Astm\Protocol\LlpEncoder;
use Astm\Receiver;
use Astm\Records\Result;
use Astm\Sender;
use Astm\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class LlpAndBuilderTest extends TestCase
{
    // -----------------------------------------------------------------------
    //  Frame checksum
    // -----------------------------------------------------------------------

    public function test_checksum_calculation(): void
    {
        // From ASTM spec example: frame "1H|\^&" → ETX → verify
        $payload  = '1' . 'H|\\^&' . Ascii::ETX;
        $checksum = Frame::checksum($payload);

        // Checksum must be exactly 2 uppercase hex characters
        $this->assertMatchesRegularExpression('/^[0-9A-F]{2}$/', $checksum);
    }

    public function test_frame_encode_structure(): void
    {
        $frame  = new Frame('H|\\^&|||test', 1, true);
        $binary = $frame->encode();

        $this->assertStringStartsWith(Ascii::STX, $binary);
        $this->assertStringEndsWith(Ascii::CR . Ascii::LF, $binary);
        $this->assertStringContainsString(Ascii::ETX, $binary);
    }

    public function test_frame_number_cycles_0_to_7(): void
    {
        // Frame number 8 should wrap to 0 (8 % 8 = 0)
        $frame  = new Frame('data', 8, true);
        $binary = $frame->encode();
        $this->assertSame('0', $binary[1]); // position 1 = frame number after STX
    }

    public function test_checksum_verification(): void
    {
        $payload = '1' . 'some test data' . Ascii::ETX;
        $cs      = Frame::checksum($payload);

        $this->assertTrue(Frame::verifyChecksum($payload, $cs));
        $this->assertFalse(Frame::verifyChecksum($payload, 'XX'));
    }

    // -----------------------------------------------------------------------
    //  LLP Encoder
    // -----------------------------------------------------------------------

    public function test_encoder_produces_one_frame_per_record(): void
    {
        $message = $this->buildSimpleMessage();
        $frames  = (new LlpEncoder())->encode($message);

        // H + P + O + R + R + L = 6 records → 6 frames (all short enough)
        $this->assertCount(6, $frames);
    }

    public function test_last_frame_uses_etx(): void
    {
        $message = $this->buildSimpleMessage();
        $frames  = (new LlpEncoder())->encode($message);

        $this->assertTrue($frames[array_key_last($frames)]->isLast);
    }

    public function test_intermediate_frames_use_etb_when_data_long(): void
    {
        $encoder = new LlpEncoder(maxDataBytes: 10); // force splitting
        $frames  = $encoder->encodeLines(['ABCDEFGHIJKLMNOPQRSTUVWXYZ']); // 26 chars > 10

        // Should be split into 3 chunks (10+10+6)
        $this->assertCount(3, $frames);
        $this->assertFalse($frames[0]->isLast);
        $this->assertFalse($frames[1]->isLast);
        $this->assertTrue($frames[2]->isLast);
    }

    // -----------------------------------------------------------------------
    //  LLP Decoder
    // -----------------------------------------------------------------------

    public function test_decode_single_frame(): void
    {
        $frame = new Frame('H|\\^&|||sender||||||||E1394-97', 1, false);
        $next  = new Frame('L|1|N', 2, true);

        $decoder = new LlpDecoder(verifyChecksums: true);

        // Feed raw bytes
        $decoder->feed($frame->encode());
        $decoder->feed($next->encode());
        $decoder->feed(Ascii::EOT);

        $this->assertTrue($decoder->hasMessage());
        $msg = $decoder->popMessage();
        $this->assertNotNull($msg);
        $this->assertStringContainsString('H|', $msg);
    }

    public function test_decoder_rejects_bad_checksum(): void
    {
        // Manually corrupt a frame's checksum
        $frame   = (new Frame('H|\\^&|||sender', 1, true))->encode();
        $corrupt = substr($frame, 0, -4) . 'ZZ' . Ascii::CR . Ascii::LF;

        $this->expectException(\Astm\Exceptions\ParseException::class);
        $this->expectExceptionMessageMatches('/checksum mismatch/i');

        (new LlpDecoder(verifyChecksums: true))->decode($corrupt . Ascii::EOT);
    }

    public function test_decoder_skips_checksum_when_disabled(): void
    {
        $frame   = (new Frame('H|\\^&|||sender', 1, false))->encode();
        $l       = (new Frame('L|1|N', 2, true))->encode();
        $corrupt = substr($frame, 0, -4) . 'ZZ' . Ascii::CR . Ascii::LF;

        $decoder  = new LlpDecoder(verifyChecksums: false);
        $messages = $decoder->decode($corrupt . $l . Ascii::EOT);

        $this->assertNotEmpty($messages);
    }

    // -----------------------------------------------------------------------
    //  Round-trip: encode then decode
    // -----------------------------------------------------------------------

    public function test_encode_decode_round_trip(): void
    {
        $original = $this->buildSimpleMessage();
        $encoder  = new LlpEncoder();
        $decoder  = new LlpDecoder();

        // Simulate full session: ENQ [frames] EOT
        $blob = Ascii::ENQ;
        foreach ($encoder->encode($original) as $frame) {
            $blob .= $frame->encode();
        }
        $blob .= Ascii::EOT;

        // Strip ENQ/EOT to get just frames for decoder
        $framesOnly = substr($blob, 1, -1) . Ascii::EOT;
        $messages   = $decoder->decode($framesOnly);

        $this->assertCount(1, $messages);

        $parsed  = (new Parser())->parse($messages[0]);
        $results = $parsed->getResults();

        $this->assertCount(2, $results);
        $this->assertSame('WBC', $results[0]->getTestName());
        $this->assertSame('7.2', $results[0]->getValue());
    }

    // -----------------------------------------------------------------------
    //  Message Builder
    // -----------------------------------------------------------------------

    public function test_builder_creates_valid_message(): void
    {
        $msg = MessageBuilder::create()
            ->sender('TEST-LIS', 'v1.0')
            ->patient(fn($p) => $p->id('P001')->name('Doe', 'Jane')->sex('F')->birthdate('19900101'))
            ->order(fn($o) => $o->specimenId('S-999')->addTest('WBC')->addTest('RBC')->reportType('F'))
            ->result(fn($r) => $r->test('WBC')->value('7.2')->units('10*3/uL')->flag('N'))
            ->result(fn($r) => $r->test('RBC')->value('4.5')->units('10*6/uL')->flag('N'))
            ->build();

        $this->assertNotNull($msg->getHeader());
        $this->assertNotNull($msg->getFirstPatient());
        $this->assertNotNull($msg->getFirstOrder());
        $this->assertCount(2, $msg->getResults());
        $this->assertNotNull($msg->getTerminator());
    }

    public function test_builder_header_fields(): void
    {
        $msg    = MessageBuilder::create()->sender('MY-INSTRUMENT', 'v2.0')->build();
        $header = $msg->getHeader();

        $this->assertSame('MY-INSTRUMENT', $header->getSenderName());
        $this->assertSame('v2.0',          $header->getSenderVersion());
        $this->assertSame('E1394-97',      $header->getVersionNumber());
        $this->assertSame('P',             $header->getProcessingId());
    }

    public function test_builder_patient_fields(): void
    {
        $msg     = MessageBuilder::create()
            ->sender()
            ->patient(fn($p) => $p
                ->id('PAT-001', 'LAB-001')
                ->name('Smith', 'John', 'A')
                ->sex('M')
                ->birthdate('19850315'))
            ->build();

        $patient = $msg->getFirstPatient();

        $this->assertSame('PAT-001',  $patient->getPracticePatientId());
        $this->assertSame('LAB-001',  $patient->getLabPatientId());
        $this->assertSame('Smith',    $patient->getLastName());
        $this->assertSame('John',     $patient->getFirstName());
        $this->assertSame('M',        $patient->getSex());
        $this->assertSame('19850315', $patient->getBirthdate());
    }

    public function test_builder_order_test_ids(): void
    {
        $msg   = MessageBuilder::create()
            ->sender()
            ->order(fn($o) => $o->specimenId('S1')->addTest('WBC')->addTest('PLT'))
            ->build();

        $names = $msg->getFirstOrder()->getTestNames();
        $this->assertContains('WBC', $names);
        $this->assertContains('PLT', $names);
    }

    public function test_builder_result_defaults_to_final_status(): void
    {
        $msg = MessageBuilder::create()
            ->sender()
            ->result(fn($r) => $r->test('HGB')->value('12.0')->units('g/dL'))
            ->build();

        $this->assertSame('F', $msg->getResults()[0]->getResultStatus());
    }

    public function test_builder_result_map_accessible(): void
    {
        $msg = MessageBuilder::create()
            ->sender()
            ->result(fn($r) => $r->test('MCV')->value('85.0')->units('fL')->flag('N'))
            ->result(fn($r) => $r->test('MCH')->value('27.0')->units('pg')->flag('L'))
            ->build();

        $map = $msg->getResultMap();

        $this->assertArrayHasKey('MCV', $map);
        $this->assertArrayHasKey('MCH', $map);
        $this->assertSame('L', $map['MCH']['flag']);
    }

    public function test_builder_sequence_numbers_increment(): void
    {
        $msg = MessageBuilder::create()
            ->sender()
            ->result(fn($r) => $r->test('WBC')->value('7.0'))
            ->result(fn($r) => $r->test('RBC')->value('4.5'))
            ->result(fn($r) => $r->test('HGB')->value('13.0'))
            ->build();

        $results = $msg->getResults();

        $this->assertSame('1', $results[0]->getSequenceNumber());
        $this->assertSame('2', $results[1]->getSequenceNumber());
        $this->assertSame('3', $results[2]->getSequenceNumber());
    }

    public function test_builder_comment(): void
    {
        $msg = MessageBuilder::create()
            ->sender()
            ->comment('Sample haemolysed')
            ->build();

        $comments = $msg->getComments();
        $this->assertCount(1, $comments);
        $this->assertSame('Sample haemolysed', $comments[0]->getCommentText());
    }

    public function test_builder_terminates_message(): void
    {
        $msg  = MessageBuilder::create()->sender()->build();
        $term = $msg->getTerminator();

        $this->assertNotNull($term);
        $this->assertTrue($term->isNormalTermination());
    }

    public function test_build_is_idempotent(): void
    {
        $builder = MessageBuilder::create()->sender();
        $msg1    = $builder->build();
        $msg2    = $builder->build();

        // Second build() should not add a second terminator
        $terminators = $msg2->getRecordsByType('L');
        $this->assertCount(1, $terminators);
    }

    // -----------------------------------------------------------------------
    //  Sender + MemoryTransport
    // -----------------------------------------------------------------------

    public function test_sender_performs_enq_ack_handshake(): void
    {
        $transport = new MemoryTransport();

        // Pre-load ACKs: one for ENQ + one per frame (6 records)
        $transport->queueIncoming(str_repeat(Ascii::ACK, 7));
        $transport->connect();

        $message = $this->buildSimpleMessage();
        (new Sender($transport))->send($message);

        $written = $transport->getWritten();

        // Should start with ENQ
        $this->assertSame(Ascii::ENQ, $written[0]);
        // Should end with EOT
        $this->assertSame(Ascii::EOT, $written[strlen($written) - 1]);
        // Should contain STX frames
        $this->assertStringContainsString(Ascii::STX, $written);
    }

    public function test_sender_retries_on_nak_then_succeeds(): void
    {
        $transport = new MemoryTransport();

        // NAK on first ENQ, then ACK, then ACK for each frame (6)
        $transport->queueIncoming(Ascii::NAK . str_repeat(Ascii::ACK, 7));
        $transport->connect();

        // Should not throw
        (new Sender($transport, maxEnqRetries: 3))->send($this->buildSimpleMessage());

        $this->assertTrue(true); // reached here without exception
    }

    public function test_sender_throws_after_max_enq_retries(): void
    {
        $transport = new MemoryTransport();
        $transport->queueIncoming(str_repeat(Ascii::NAK, 10));
        $transport->connect();

        $this->expectException(\Astm\Exceptions\AstmException::class);
        $this->expectExceptionMessageMatches('/did not ACK/i');

        (new Sender($transport, maxEnqRetries: 3))->send($this->buildSimpleMessage());
    }

    // -----------------------------------------------------------------------
    //  Receiver + MemoryTransport
    // -----------------------------------------------------------------------

    public function test_receiver_decodes_incoming_session(): void
    {
        $message = $this->buildSimpleMessage();
        $encoder = new LlpEncoder();

        // Build the byte stream an instrument would send
        $stream = Ascii::ENQ;
        foreach ($encoder->encode($message) as $frame) {
            $stream .= $frame->encode();
        }
        $stream .= Ascii::EOT;

        $transport = new MemoryTransport();
        $transport->connect();
        $transport->queueIncoming($stream);

        $received = [];
        $receiver = new Receiver($transport, onMessage: function (Message $m) use (&$received) {
            $received[] = $m;
        });

        // Drain all bytes via tick()
        for ($i = 0; $i < 200; $i++) {
            $receiver->tick();
        }

        $this->assertCount(1, $received);
        $this->assertSame('TEST-LIS', $received[0]->getHeader()->getSenderName());
        $this->assertCount(2, $received[0]->getResults());
    }

    public function test_receiver_sends_ack_for_each_frame(): void
    {
        $message = $this->buildSimpleMessage();
        $encoder = new LlpEncoder();

        $stream = Ascii::ENQ;
        foreach ($encoder->encode($message) as $frame) {
            $stream .= $frame->encode();
        }
        $stream .= Ascii::EOT;

        $transport = new MemoryTransport();
        $transport->connect();
        $transport->queueIncoming($stream);

        $receiver = new Receiver($transport);
        for ($i = 0; $i < 200; $i++) {
            $receiver->tick();
        }

        $written = $transport->getWritten();
        // One ACK for ENQ + one ACK per frame (6 records)
        $ackCount = substr_count($written, Ascii::ACK);
        $this->assertGreaterThanOrEqual(7, $ackCount);
    }

    // -----------------------------------------------------------------------
    //  Full end-to-end: Builder → Sender → (wire) → Receiver → Parser
    // -----------------------------------------------------------------------

    public function test_full_pipeline(): void
    {
        // 1. Build a message
        $outbound = MessageBuilder::create()
            ->sender('HAEM-9000', 'v3.1')
            ->patient(fn($p) => $p->id('XYZ-999')->name('Müller', 'Hans')->sex('M'))
            ->order(fn($o) => $o->specimenId('EDTA-007')->addTest('WBC')->addTest('HGB'))
            ->result(fn($r) => $r->test('WBC')->value('9.1')->units('10*3/uL')->flag('N'))
            ->result(fn($r) => $r->test('HGB')->value('14.2')->units('g/dL')->flag('N'))
            ->build();

        // 2. Encode to frames
        $encoder = new LlpEncoder();
        $frames  = $encoder->encode($outbound);

        // 3. Simulate instrument transmission (ENQ + frames + EOT)
        $wire = Ascii::ENQ . implode('', array_map(fn($f) => $f->encode(), $frames)) . Ascii::EOT;

        // 4. Receive and decode
        $transport = new MemoryTransport();
        $transport->connect();
        $transport->queueIncoming($wire);

        $inbound  = null;
        $receiver = new Receiver($transport, onMessage: function (Message $m) use (&$inbound) {
            $inbound = $m;
        });

        for ($i = 0; $i < 300; $i++) {
            $receiver->tick();
        }

        // 5. Assert fidelity
        $this->assertNotNull($inbound);
        $this->assertSame('HAEM-9000', $inbound->getHeader()->getSenderName());
        $this->assertSame('Müller',    $inbound->getFirstPatient()->getLastName());

        $map = $inbound->getResultMap();
        $this->assertArrayHasKey('WBC', $map);
        $this->assertSame('9.1', $map['WBC']['value']);
        $this->assertSame('14.2', $map['HGB']['value']);
    }

    // -----------------------------------------------------------------------
    //  Helper
    // -----------------------------------------------------------------------

    private function buildSimpleMessage(): Message
    {
        return MessageBuilder::create()
            ->sender('TEST-LIS', 'v1.0')
            ->patient(fn($p) => $p->id('P001')->name('Doe', 'Jane')->sex('F'))
            ->order(fn($o) => $o->specimenId('S-001')->addTest('WBC')->addTest('RBC'))
            ->result(fn($r) => $r->test('WBC')->value('7.2')->units('10*3/uL')->flag('N'))
            ->result(fn($r) => $r->test('RBC')->value('4.1')->units('10*6/uL')->flag('N'))
            ->build();
    }
}
