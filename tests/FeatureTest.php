<?php

declare(strict_types=1);

namespace Astm\Tests;

use Astm\Astm;
use Astm\Delimiters;
use Astm\EscapeCodec;
use Astm\Message;
use Astm\MessageBuilder;
use Astm\MessageCollection;
use Astm\MessageValidator;
use Astm\Parser;
use Astm\Protocol\Ascii;
use Astm\Protocol\Frame;
use Astm\Protocol\LlpDecoder;
use Astm\Protocol\LlpEncoder;
use Astm\Receiver;
use Astm\Records\Result;
use Astm\Sender;
use Astm\Transport\FileTransport;
use Astm\Transport\MemoryTransport;
use PHPUnit\Framework\TestCase;

final class FeatureTest extends TestCase
{
    // -----------------------------------------------------------------------
    //  EscapeCodec
    // -----------------------------------------------------------------------

    public function test_encode_field_delimiter(): void
    {
        $codec = new EscapeCodec(new Delimiters());
        $this->assertSame('hello&F&world', $codec->encode('hello|world'));
    }

    public function test_encode_component_delimiter(): void
    {
        $codec = new EscapeCodec(new Delimiters());
        $this->assertSame('A&S&B', $codec->encode('A^B'));
    }

    public function test_encode_repeat_delimiter(): void
    {
        $codec = new EscapeCodec(new Delimiters());
        $this->assertSame('A&R&B', $codec->encode('A\\B'));
    }

    public function test_encode_escape_char_itself(): void
    {
        $codec = new EscapeCodec(new Delimiters());
        $this->assertSame('a&E&b', $codec->encode('a&b'));
    }

    public function test_encode_then_decode_roundtrip(): void
    {
        $codec = new EscapeCodec(new Delimiters());
        $raw   = 'Result: 5.0 | units: 10*3/uL ^ reference: 4.0-11.0 & note';
        $this->assertSame($raw, $codec->decode($codec->encode($raw)));
    }

    public function test_decode_all_sequences(): void
    {
        $codec = new EscapeCodec(new Delimiters());
        $this->assertSame('|\\^&', $codec->decode('&F&&R&&S&&E&'));
    }

    public function test_codec_respects_custom_delimiters(): void
    {
        $d     = new Delimiters(field: '!', component: '@', repeat: '#', escape: '$');
        $codec = new EscapeCodec($d);
        $this->assertSame('a$F$b', $codec->encode('a!b'));
        $this->assertSame('a!b',   $codec->decode('a$F$b'));
    }

    // -----------------------------------------------------------------------
    //  MessageValidator
    // -----------------------------------------------------------------------

    public function test_valid_message_passes(): void
    {
        $msg = $this->buildMessage();
        $v   = new MessageValidator();
        $this->assertTrue($v->validate($msg));
        $this->assertEmpty($v->getErrors());
    }

    public function test_missing_header_fails(): void
    {
        // Build message then strip H record
        $msg     = $this->buildMessage();
        $records = $msg->getRecords();
        // Create a message starting with P instead of H
        $msg2 = new Message();
        foreach (array_slice($records, 1) as $r) {
            $msg2->addRecord($r);
        }
        $v = new MessageValidator();
        $this->assertFalse($v->validate($msg2));
        $this->assertNotEmpty($v->getErrors());
    }

    public function test_missing_terminator_fails(): void
    {
        $msg     = $this->buildMessage();
        $records = $msg->getRecords();
        $msg2    = new Message();
        foreach (array_slice($records, 0, -1) as $r) {
            $msg2->addRecord($r);
        }
        $v = new MessageValidator();
        $this->assertFalse($v->validate($msg2));
    }

    public function test_wrong_sequence_number_fails(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn($r) => $r->test('WBC')->value('5.0'))
            ->build();

        // Manually corrupt the sequence number of the R record
        $results = $msg->getResults();
        $results[0]->setField(2, '99'); // should be 1

        $v = new MessageValidator();
        $this->assertFalse($v->validate($msg));
        $this->assertStringContainsString('sequence number', $v->getErrors()[0]);
    }

    public function test_result_with_no_test_name_fails(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn($r) => $r->universalTestId('')->value('5.0'))
            ->build();

        $v = new MessageValidator();
        $this->assertFalse($v->validate($msg));
    }

    public function test_invalid_result_status_fails(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn($r) => $r->test('WBC')->value('5.0')->status('Z'))
            ->build();

        $v = new MessageValidator();
        $this->assertFalse($v->validate($msg));
        $this->assertStringContainsString("unrecognised result status 'Z'", $v->getErrors()[0]);
    }

    public function test_astm_facade_validate(): void
    {
        $msg    = $this->buildMessage();
        $errors = Astm::validate($msg);
        $this->assertEmpty($errors);
    }

    // -----------------------------------------------------------------------
    //  MessageCollection
    // -----------------------------------------------------------------------

    public function test_collection_from_string_single_message(): void
    {
        $raw  = $this->rawMessage();
        $coll = MessageCollection::fromString($raw);
        $this->assertCount(1, $coll);
    }

    public function test_collection_from_string_multiple_messages(): void
    {
        $raw  = $this->rawMessage() . "\n" . $this->rawMessage();
        $coll = MessageCollection::fromString($raw);
        $this->assertCount(2, $coll);
    }

    public function test_collection_get_all_results(): void
    {
        $raw  = $this->rawMessage() . "\n" . $this->rawMessage();
        $coll = MessageCollection::fromString($raw);
        // Each message has 2 R records → 4 total
        $this->assertCount(4, $coll->getAllResults());
    }

    public function test_collection_get_abnormal_results(): void
    {
        $raw  = $this->rawMessage(); // contains 1 H-flag result
        $coll = MessageCollection::fromString($raw);
        $this->assertNotEmpty($coll->getAbnormalResults());
    }

    public function test_collection_get_results_by_test(): void
    {
        $raw  = $this->rawMessage() . "\n" . $this->rawMessage();
        $coll = MessageCollection::fromString($raw);
        $this->assertCount(2, $coll->getResultsByTest('WBC'));
    }

    public function test_collection_messages_with_abnormalities(): void
    {
        $raw  = $this->rawMessage();
        $coll = MessageCollection::fromString($raw);
        $this->assertNotEmpty($coll->getMessagesWithAbnormalities());
    }

    public function test_collection_iterable(): void
    {
        $coll = MessageCollection::fromString($this->rawMessage());
        $seen = 0;
        foreach ($coll as $msg) {
            $this->assertInstanceOf(Message::class, $msg);
            $seen++;
        }
        $this->assertSame(1, $seen);
    }

    public function test_collection_add_is_immutable(): void
    {
        $c1 = MessageCollection::fromString($this->rawMessage());
        $c2 = $c1->add($this->buildMessage());
        $this->assertCount(1, $c1);
        $this->assertCount(2, $c2);
    }

    public function test_collection_first_last(): void
    {
        $raw  = $this->rawMessage() . "\n" . $this->rawMessage();
        $coll = MessageCollection::fromString($raw);
        $this->assertNotNull($coll->first());
        $this->assertNotNull($coll->last());
    }

    public function test_collection_from_file(): void
    {
        $path = sys_get_temp_dir() . '/astm_test_' . uniqid() . '.txt';
        file_put_contents($path, $this->rawMessage());
        $coll = MessageCollection::fromFile($path);
        $this->assertCount(1, $coll);
        unlink($path);
    }

    // -----------------------------------------------------------------------
    //  FileTransport
    // -----------------------------------------------------------------------

    public function test_file_transport_write_and_read(): void
    {
        $path = sys_get_temp_dir() . '/astm_ft_' . uniqid() . '.bin';

        // Write
        $wt = FileTransport::forWriting($path);
        $wt->connect();
        $wt->write('HELLO');
        $wt->write('WORLD');
        $wt->disconnect();

        // Read back
        $rt = FileTransport::forReading($path);
        $rt->connect();
        $data = $rt->read(1024);
        $rt->disconnect();

        $this->assertSame('HELLOWORLD', $data);
        unlink($path);
    }

    public function test_file_transport_readbyte(): void
    {
        $path = sys_get_temp_dir() . '/astm_fb_' . uniqid() . '.bin';
        file_put_contents($path, 'ABC');

        $rt = FileTransport::forReading($path);
        $rt->connect();
        $this->assertSame('A', $rt->readByte());
        $this->assertSame('B', $rt->readByte());
        $this->assertSame('C', $rt->readByte());
        $rt->disconnect();
        unlink($path);
    }

    public function test_file_transport_appending(): void
    {
        $path = sys_get_temp_dir() . '/astm_fa_' . uniqid() . '.bin';
        file_put_contents($path, 'HELLO');

        $at = FileTransport::forAppending($path);
        $at->connect();
        $at->write('WORLD');
        $at->disconnect();

        $this->assertSame('HELLOWORLD', file_get_contents($path));
        unlink($path);
    }

    public function test_file_transport_not_connected_throws(): void
    {
        $this->expectException(\Astm\Exceptions\AstmException::class);
        $rt = FileTransport::forReading('/dev/null');
        $rt->write('test'); // not connected
    }

    // -----------------------------------------------------------------------
    //  Astm facade – parse / build / validate
    // -----------------------------------------------------------------------

    public function test_facade_parse(): void
    {
        $msg = Astm::parse($this->rawMessage());
        $this->assertNotNull($msg->getHeader());
        $this->assertCount(2, $msg->getResults());
    }

    public function test_facade_parse_file(): void
    {
        $path = sys_get_temp_dir() . '/astm_pf_' . uniqid() . '.txt';
        file_put_contents($path, $this->rawMessage());
        $coll = Astm::parseFile($path);
        $this->assertCount(1, $coll);
        unlink($path);
    }

    public function test_facade_build(): void
    {
        $msg = Astm::build()
            ->sender('FACADE-LIS', 'v1.0')
            ->result(fn($r) => $r->test('HGB')->value('13.5')->units('g/dL')->flag('N'))
            ->build();

        $this->assertSame('FACADE-LIS', $msg->getHeader()->getSenderName());
        $this->assertCount(1, $msg->getResults());
    }

    public function test_facade_escape_codec(): void
    {
        $codec = Astm::escapeCodec();
        $this->assertSame('a&F&b', $codec->encode('a|b'));
    }

    // -----------------------------------------------------------------------
    //  Astm facade – writeFile / readFile (LLP round-trip via files)
    // -----------------------------------------------------------------------

    public function test_facade_write_and_read_file(): void
    {
        $path = sys_get_temp_dir() . '/astm_llp_' . uniqid() . '.astm';

        $original = Astm::build()
            ->sender('FILE-LIS', 'v2.0')
            ->patient(fn($p) => $p->id('P999')->name('Tanaka', 'Yuki')->sex('F'))
            ->result(fn($r) => $r->test('PLT')->value('250')->units('10*3/uL')->flag('N'))
            ->result(fn($r) => $r->test('MPV')->value('10.1')->units('fL')->flag('H'))
            ->build();

        Astm::writeFile($original, $path);
        $this->assertFileExists($path);

        $collection = Astm::readFile($path);
        $this->assertCount(1, $collection);

        $recovered = $collection->first();
        $this->assertNotNull($recovered);
        $this->assertSame('FILE-LIS', $recovered->getHeader()->getSenderName());
        $this->assertSame('Tanaka',   $recovered->getFirstPatient()->getLastName());

        $map = $recovered->getResultMap();
        $this->assertSame('250',  $map['PLT']['value']);
        $this->assertSame('10.1', $map['MPV']['value']);
        $this->assertSame('H',    $map['MPV']['flag']);

        unlink($path);
    }

    // -----------------------------------------------------------------------
    //  Astm facade – decodeLlp
    // -----------------------------------------------------------------------

    public function test_facade_decode_llp_binary(): void
    {
        $msg  = $this->buildMessage();
        $enc  = new LlpEncoder();

        $blob = Ascii::ENQ;
        foreach ($enc->encode($msg) as $frame) {
            $blob .= $frame->encode();
        }
        $blob .= Ascii::EOT;

        $coll = Astm::decodeLlp($blob);
        $this->assertCount(1, $coll);
        $this->assertCount(2, $coll->first()->getResults());
    }

    // -----------------------------------------------------------------------
    //  Full multi-session collection via Receiver
    // -----------------------------------------------------------------------

    public function test_receiver_handles_multiple_sessions(): void
    {
        $enc  = new LlpEncoder();
        $msgs = [
            $this->buildMessage(),
            $this->buildMessage(),
            $this->buildMessage(),
        ];

        // Build a stream of 3 back-to-back ENQ…EOT sessions
        $stream = '';
        foreach ($msgs as $msg) {
            $stream .= Ascii::ENQ;
            foreach ($enc->encode($msg) as $frame) {
                $stream .= $frame->encode();
            }
            $stream .= Ascii::EOT;
        }

        $transport = new MemoryTransport();
        $transport->connect();
        $transport->queueIncoming($stream);

        $received = [];
        $receiver = new Receiver($transport, onMessage: function (Message $m) use (&$received): void {
            $received[] = $m;
        });

        for ($i = 0; $i < 2000; $i++) {
            $receiver->tick();
        }

        $this->assertCount(3, $received);
        foreach ($received as $m) {
            $this->assertCount(2, $m->getResults());
        }
    }

    // -----------------------------------------------------------------------
    //  Long record ETB/ETX splitting
    // -----------------------------------------------------------------------

    public function test_long_record_split_and_reassembled(): void
    {
        // Create a record line longer than 30 bytes to force ETB splits
        $longValue = str_repeat('X', 100);
        $msg  = Astm::build()
            ->sender('LONG-LIS')
            ->result(fn($r) => $r->test('NOTES')->value($longValue)->units(''))
            ->build();

        $encoder = new LlpEncoder(maxDataBytes: 30);
        $frames  = $encoder->encode($msg);

        // Some frames must use ETB
        $etbFrames = array_filter($frames, fn(Frame $f) => !$f->isLast);
        $this->assertNotEmpty($etbFrames);

        // Encode the full session
        $stream = Ascii::ENQ;
        foreach ($frames as $frame) {
            $stream .= $frame->encode();
        }
        $stream .= Ascii::EOT;

        // Receive and re-parse
        $transport = new MemoryTransport();
        $transport->connect();
        $transport->queueIncoming($stream);

        $got = null;
        $receiver = new Receiver($transport, onMessage: function (Message $m) use (&$got): void {
            $got = $m;
        });
        for ($i = 0; $i < 1000; $i++) {
            $receiver->tick();
        }

        $this->assertNotNull($got);
        $results = $got->getResults();
        $this->assertNotEmpty($results);
        $this->assertSame($longValue, $results[0]->getValue());
    }

    // -----------------------------------------------------------------------
    //  Delimiters
    // -----------------------------------------------------------------------

    public function test_custom_delimiters_propagate(): void
    {
        $d = new Delimiters(field: '!', component: '@', repeat: '#', escape: '$');

        $msg = MessageBuilder::create($d)
            ->sender('CUSTOM-LIS')
            ->result(fn($r) => $r->test('WBC')->value('5.0')->units('10*3/uL')->flag('N'))
            ->build();

        $line = $msg->getResults()[0]->toString();
        // Fields should be separated by '!'
        $this->assertStringContainsString('!', $line);
        $this->assertStringNotContainsString('|', $line);
    }

    // -----------------------------------------------------------------------
    //  Sender – max retries for frame NAK
    // -----------------------------------------------------------------------

    public function test_sender_throws_after_max_frame_retries(): void
    {
        $transport = new MemoryTransport();
        // ACK for ENQ, then NAK for every frame
        $transport->queueIncoming(Ascii::ACK . str_repeat(Ascii::NAK, 20));
        $transport->connect();

        $this->expectException(\Astm\Exceptions\AstmException::class);
        $this->expectExceptionMessageMatches('/not ACKed/i');

        (new Sender($transport, maxFrameRetries: 3))->send($this->buildMessage());
    }

    // -----------------------------------------------------------------------
    //  Message serialisation
    // -----------------------------------------------------------------------

    public function test_message_to_string_round_trips_through_parser(): void
    {
        $original = Astm::build()
            ->sender('SERIAL-LIS', 'v1.0')
            ->patient(fn($p) => $p->id('P-RND')->name('Okafor', 'Ngozi')->sex('F')->birthdate('19920801'))
            ->order(fn($o) => $o->specimenId('EDTA-RND')->addTest('WBC')->addTest('PLT'))
            ->result(fn($r) => $r->test('WBC')->value('6.1')->units('10*3/uL')->flag('N'))
            ->result(fn($r) => $r->test('PLT')->value('312')->units('10*3/uL')->flag('N'))
            ->build();

        $serialised = $original->toString("\n");
        $recovered  = Astm::parse($serialised);

        $this->assertSame('SERIAL-LIS', $recovered->getHeader()->getSenderName());
        $this->assertSame('Okafor',     $recovered->getFirstPatient()->getLastName());
        $this->assertSame('Ngozi',      $recovered->getFirstPatient()->getFirstName());
        $this->assertSame('19920801',   $recovered->getFirstPatient()->getBirthdate());

        $map = $recovered->getResultMap();
        $this->assertSame('6.1', $map['WBC']['value']);
        $this->assertSame('312', $map['PLT']['value']);
    }

    // -----------------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------------

    private function buildMessage(): Message
    {
        return Astm::build()
            ->sender('TEST-LIS', 'v1.0')
            ->patient(fn($p) => $p->id('P001')->name('Doe', 'Jane')->sex('F')->birthdate('19900101'))
            ->order(fn($o) => $o->specimenId('S-001')->addTest('WBC')->addTest('HGB')->reportType('F'))
            ->result(fn($r) => $r->test('WBC')->value('7.2')->units('10*3/uL')->flag('N'))
            ->result(fn($r) => $r->test('HGB')->value('16.5')->units('g/dL')->flag('H'))
            ->build();
    }

    private function rawMessage(): string
    {
        return $this->buildMessage()->toString("\n");
    }
}
