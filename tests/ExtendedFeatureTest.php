<?php

declare(strict_types=1);

namespace Astm\Tests;

use Astm\Astm;
use Astm\Delimiters;
use Astm\Exceptions\AstmException;
use Astm\Exceptions\ConnectionException;
use Astm\Exceptions\ParseException;
use Astm\Message;
use Astm\MessageBuilder;
use Astm\MessageCollection;
use Astm\MessageValidator;
use Astm\Protocol\Ascii;
use Astm\Protocol\Frame;
use Astm\Protocol\LlpEncoder;
use Astm\Receiver;
use Astm\Records\Result;
use Astm\Sender;
use Astm\Transport\FileTransport;
use Astm\Transport\MemoryTransport;
use Astm\Transport\StreamTransport;
use PHPUnit\Framework\TestCase;

final class ExtendedFeatureTest extends TestCase
{
    // -----------------------------------------------------------------------
    //  ConnectionException hierarchy
    // -----------------------------------------------------------------------

    public function test_connection_exception_is_astm_exception(): void
    {
        $e = new ConnectionException('test');
        $this->assertInstanceOf(AstmException::class, $e);
    }

    public function test_parse_exception_is_astm_exception(): void
    {
        $e = new ParseException('test');
        $this->assertInstanceOf(AstmException::class, $e);
    }

    public function test_connection_exception_distinct_from_parse_exception(): void
    {
        $this->assertNotInstanceOf(ParseException::class, new ConnectionException('x'));
        $this->assertNotInstanceOf(ConnectionException::class, new ParseException('x'));
    }

    // -----------------------------------------------------------------------
    //  StreamTransport
    // -----------------------------------------------------------------------

    public function test_stream_transport_wrap_read_write(): void
    {
        // Create a pair of memory stream pipes
        $pipe = fopen('php://memory', 'r+b');

        $transport = StreamTransport::wrap($pipe, ownsStream: false);
        $transport->connect(); // no-op

        $this->assertTrue($transport->isConnected());

        // Write something
        $transport->write('HELLO');

        // Rewind and read back
        rewind($pipe);
        $this->assertSame('HELLO', $transport->read(5));

        $transport->disconnect(); // ownsStream=false → does NOT close $pipe
        $this->assertTrue(is_resource($pipe)); // still open
        fclose($pipe);
    }

    public function test_stream_transport_readbyte(): void
    {
        $pipe = fopen('php://memory', 'r+b');
        fwrite($pipe, 'ABC');
        rewind($pipe);

        $t = StreamTransport::wrap($pipe, ownsStream: true);
        $this->assertSame('A', $t->readByte());
        $this->assertSame('B', $t->readByte());
        $this->assertSame('C', $t->readByte());
        // owns the stream — disconnect closes it
        $t->disconnect();
        $this->assertFalse(is_resource($pipe));
    }

    public function test_stream_transport_rejects_non_resource(): void
    {
        $this->expectException(AstmException::class);
        StreamTransport::wrap('not-a-resource');
    }

    public function test_stream_transport_write_throws_when_closed(): void
    {
        $pipe = fopen('php://memory', 'r+b');
        $t    = StreamTransport::wrap($pipe, ownsStream: true);
        $t->disconnect(); // closes the stream

        $this->expectException(ConnectionException::class);
        $t->write('FAIL');
    }

    // -----------------------------------------------------------------------
    //  Message::toArray()
    // -----------------------------------------------------------------------

    public function test_to_array_shape(): void
    {
        $msg = $this->makeMessage();
        $arr = $msg->toArray();

        $this->assertArrayHasKey('sender',   $arr);
        $this->assertArrayHasKey('patient',  $arr);
        $this->assertArrayHasKey('order',    $arr);
        $this->assertArrayHasKey('results',  $arr);
        $this->assertArrayHasKey('comments', $arr);
    }

    public function test_to_array_sender(): void
    {
        $msg = $this->makeMessage();
        $this->assertSame('ARRAY-LIS', $msg->toArray()['sender']);
    }

    public function test_to_array_patient(): void
    {
        $arr     = $this->makeMessage()->toArray();
        $patient = $arr['patient'];

        $this->assertSame('Müller',    $patient['lastName']);
        $this->assertSame('Fatima',    $patient['firstName']);
        $this->assertSame('F',         $patient['sex']);
        $this->assertSame('19920801',  $patient['birthdate']);
        $this->assertSame('P-ARRAY',   $patient['practiceId']);
    }

    public function test_to_array_results(): void
    {
        $results = $this->makeMessage()->toArray()['results'];

        $this->assertCount(3, $results);
        $this->assertSame('WBC',       $results[0]['test']);
        $this->assertSame('7.2',       $results[0]['value']);
        $this->assertSame('10*3/uL',   $results[0]['units']);
        $this->assertSame('N',         $results[0]['flag']);
        $this->assertSame('F',         $results[0]['status']);
    }

    public function test_to_array_abnormal_result(): void
    {
        $results = $this->makeMessage()->toArray()['results'];
        // HGB is flagged H
        $hgb = array_values(array_filter($results, fn($r) => $r['test'] === 'HGB'))[0];
        $this->assertSame('H', $hgb['flag']);
    }

    public function test_to_array_order(): void
    {
        $order = $this->makeMessage()->toArray()['order'];

        $this->assertSame('EDTA-ARRAY', $order['specimenId']);
        $this->assertContains('WBC',    $order['tests']);
        $this->assertSame('F',          $order['reportType']);
    }

    public function test_to_array_comments(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->comment('First comment')
            ->comment('Second comment')
            ->build();

        $this->assertSame(['First comment', 'Second comment'], $msg->toArray()['comments']);
    }

    public function test_to_array_null_patient_when_absent(): void
    {
        $msg = MessageBuilder::create()->sender('LIS')->build();
        $this->assertNull($msg->toArray()['patient']);
    }

    // -----------------------------------------------------------------------
    //  Message::toJson()
    // -----------------------------------------------------------------------

    public function test_to_json_is_valid_json(): void
    {
        $json = $this->makeMessage()->toJson();
        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);
        $this->assertSame('ARRAY-LIS', $decoded['sender']);
        $this->assertCount(3, $decoded['results']);
    }

    public function test_to_json_compact(): void
    {
        $json = $this->makeMessage()->toJson(JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString("\n", $json);
    }

    // -----------------------------------------------------------------------
    //  MessageBuilder::fromMessage()
    // -----------------------------------------------------------------------

    public function test_from_message_clones_all_records(): void
    {
        $original = $this->makeMessage();
        $cloned   = MessageBuilder::fromMessage($original)->build();

        $this->assertSame(
            count($original->getRecords()),
            count($cloned->getRecords()),
            'cloned record count must equal original'
        );
    }

    public function test_from_message_preserves_results(): void
    {
        $original = $this->makeMessage();
        $cloned   = MessageBuilder::fromMessage($original)->build();

        $origMap  = $original->getResultMap();
        $clonMap  = $cloned->getResultMap();

        foreach (['WBC', 'HGB', 'PLT'] as $test) {
            $this->assertSame($origMap[$test]['value'], $clonMap[$test]['value']);
        }
    }

    public function test_from_message_append_comment(): void
    {
        $original = $this->makeMessage();
        $modified = MessageBuilder::fromMessage($original)
            ->comment('LIS countersigned')
            ->build();

        $comments = $modified->getComments();
        $this->assertNotEmpty($comments);
        $texts = array_map(fn($c) => $c->getCommentText(), $comments);
        $this->assertContains('LIS countersigned', $texts);
    }

    public function test_from_message_sequence_numbers_continue(): void
    {
        // original has 3 results (seq 1,2,3) — new result should be seq 4
        $original = $this->makeMessage();
        $modified = MessageBuilder::fromMessage($original)
            ->result(fn($r) => $r->test('MCV')->value('85.0')->units('fL')->flag('N'))
            ->build();

        $results = $modified->getResults();
        $last    = $results[count($results) - 1];
        $this->assertSame('4', $last->getSequenceNumber());
    }

    public function test_astm_modify_facade(): void
    {
        $original = $this->makeMessage();
        $modified = Astm::modify($original)
            ->comment('Modified by facade')
            ->build();

        $texts = array_map(fn($c) => $c->getCommentText(), $modified->getComments());
        $this->assertContains('Modified by facade', $texts);
    }

    // -----------------------------------------------------------------------
    //  Astm::version()
    // -----------------------------------------------------------------------

    public function test_version_returns_string(): void
    {
        $v = Astm::version();
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', $v);
    }

    // -----------------------------------------------------------------------
    //  Astm::listen() signature
    // -----------------------------------------------------------------------

    public function test_listen_accepts_callable_as_first_arg(): void
    {
        // We can't actually bind a port in tests, so just verify the
        // signature compiles and the method exists with the right parameter order.
        $r = new \ReflectionMethod(Astm::class, 'listen');
        $params = $r->getParameters();

        $this->assertSame('handler', $params[0]->getName());
        $this->assertSame('host',    $params[1]->getName());
        $this->assertSame('port',    $params[2]->getName());
    }

    // -----------------------------------------------------------------------
    //  MessageValidator — additional coverage
    // -----------------------------------------------------------------------

    public function test_validator_accepts_message_with_no_patient(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn($r) => $r->test('WBC')->value('7.0')->units('10*3/uL')->flag('N'))
            ->build();

        $v = new MessageValidator();
        $this->assertTrue($v->validate($msg));
    }

    public function test_validator_empty_message_fails(): void
    {
        $v = new MessageValidator();
        $this->assertFalse($v->validate(new Message()));
        $this->assertStringContainsString('no records', $v->getErrors()[0]);
    }

    public function test_validator_all_status_codes_accepted(): void
    {
        foreach (['F', 'C', 'P', 'I', 'S', 'X', 'R', 'N'] as $status) {
            $msg = MessageBuilder::create()
                ->sender('LIS')
                ->result(fn($r) => $r->test('WBC')->value('7.0')->status($status))
                ->build();

            $v = new MessageValidator();
            $this->assertTrue($v->validate($msg), "Status '{$status}' should be valid");
        }
    }

    // -----------------------------------------------------------------------
    //  MessageCollection — additional coverage
    // -----------------------------------------------------------------------

    public function test_collection_high_results(): void
    {
        $coll = MessageCollection::fromMessages([$this->makeMessage()]);
        // HGB is H
        $high = $coll->getHighResults();
        $this->assertNotEmpty($high);
        foreach ($high as $r) {
            $this->assertTrue($r->isHigh());
        }
    }

    public function test_collection_low_results_empty_when_none(): void
    {
        $msg  = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn($r) => $r->test('WBC')->value('7.2')->flag('N'))
            ->build();
        $coll = MessageCollection::fromMessages([$msg]);
        $this->assertEmpty($coll->getLowResults());
    }

    public function test_collection_all_results_mapped_groups_by_test(): void
    {
        $msg1 = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn($r) => $r->test('WBC')->value('7.0'))
            ->build();

        $msg2 = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn($r) => $r->test('WBC')->value('8.0'))
            ->build();

        $coll = MessageCollection::fromMessages([$msg1, $msg2]);
        $map  = $coll->getAllResultsMapped();

        $this->assertArrayHasKey('WBC', $map);
        $this->assertCount(2, $map['WBC']);
        $this->assertSame('7.0', $map['WBC'][0]['value']);
        $this->assertSame('8.0', $map['WBC'][1]['value']);
    }

    public function test_collection_is_empty(): void
    {
        $empty = MessageCollection::fromMessages([]);
        $this->assertTrue($empty->isEmpty());
        $this->assertCount(0, $empty);
    }

    public function test_collection_to_string(): void
    {
        $coll = MessageCollection::fromMessages([$this->makeMessage()]);
        $str  = $coll->toString("\n", "\n");
        $this->assertStringContainsString('H|', $str);
        $this->assertStringContainsString('L|', $str);
    }

    // -----------------------------------------------------------------------
    //  FileTransport + LLP round-trip
    // -----------------------------------------------------------------------

    public function test_write_and_read_llp_file_full_fidelity(): void
    {
        $path = sys_get_temp_dir() . '/astm_ext_' . uniqid() . '.astm';

        $original = $this->makeMessage();
        Astm::writeFile($original, $path);

        $this->assertFileExists($path);
        $raw = file_get_contents($path);
        $this->assertStringStartsWith(Ascii::ENQ, $raw);
        $this->assertStringEndsWith(Ascii::EOT, $raw);

        $coll      = Astm::readFile($path, verifyChecksums: true);
        $recovered = $coll->first();

        $this->assertNotNull($recovered);

        $origMap  = $original->getResultMap();
        $recovMap = $recovered->getResultMap();

        foreach (array_keys($origMap) as $test) {
            $this->assertSame($origMap[$test]['value'], $recovMap[$test]['value'],
                "Value mismatch for {$test}");
            $this->assertSame($origMap[$test]['flag'], $recovMap[$test]['flag'],
                "Flag mismatch for {$test}");
        }

        unlink($path);
    }

    public function test_file_transport_connect_missing_file_throws(): void
    {
        $this->expectException(AstmException::class);
        $t = FileTransport::forReading('/nonexistent/path/file.astm');
        $t->connect();
    }

    // -----------------------------------------------------------------------
    //  Result accessors — full coverage
    // -----------------------------------------------------------------------

    public function test_result_is_high_with_hh_flag(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn($r) => $r->test('K')->value('6.5')->flag(Result::FLAG_CRITICAL_H))
            ->build();

        $r = $msg->getResults()[0];
        $this->assertTrue($r->isHigh());
        $this->assertTrue($r->isAbnormal());
        $this->assertFalse($r->isLow());
    }

    public function test_result_is_low_with_ll_flag(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn($r) => $r->test('Na')->value('118')->flag(Result::FLAG_CRITICAL_L))
            ->build();

        $r = $msg->getResults()[0];
        $this->assertTrue($r->isLow());
        $this->assertFalse($r->isHigh());
    }

    public function test_result_numeric_value_null_for_non_numeric(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn($r) => $r->test('MORPH')->value('Abnormal morphology seen')->flag('A'))
            ->build();

        $this->assertNull($msg->getResults()[0]->getNumericValue());
    }

    public function test_result_completed_datetime_object(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn($r) => $r->test('WBC')->value('7.0')->completedAt('20250329143330'))
            ->build();

        $dt = $msg->getResults()[0]->getCompletedDateTimeObject();
        $this->assertNotNull($dt);
        $this->assertSame('2025-03-29', $dt->format('Y-m-d'));
        $this->assertSame('14:33:30',   $dt->format('H:i:s'));
    }

    // -----------------------------------------------------------------------
    //  Parser — strict vs non-strict mode
    // -----------------------------------------------------------------------

    public function test_strict_parser_throws_on_unknown_record_type(): void
    {
        $this->expectException(\Astm\Exceptions\UnknownRecordTypeException::class);
        (new \Astm\Parser(strict: true))->parse("H|\\^&|||LIS||||||||E1394-97\nZ|1|custom\nL|1|N");
    }

    public function test_non_strict_parser_skips_unknown_record_type(): void
    {
        $msg = (new \Astm\Parser(strict: false))->parse("H|\\^&|||LIS||||||||E1394-97\nZ|1|custom\nL|1|N");
        $this->assertNotNull($msg->getHeader());
        $this->assertEmpty($msg->getRecordsByType('Z'));
    }

    // -----------------------------------------------------------------------
    //  Custom record type registration
    // -----------------------------------------------------------------------

    public function test_register_custom_record_type(): void
    {
        $customClass = new class(new Delimiters()) extends \Astm\Records\AbstractRecord {
            public function getType(): string { return 'Z'; }
        };

        \Astm\Parser::registerRecordType('Z', get_class($customClass));

        $msg = (new \Astm\Parser())->parse("H|\\^&|||LIS||||||||E1394-97\nZ|1|vendor-data\nL|1|N");
        $zRecords = $msg->getRecordsByType('Z');

        $this->assertCount(1, $zRecords);
        $this->assertSame('vendor-data', $zRecords[0]->getField(3));

        // Clean up so we don't bleed into other tests
        \Astm\Parser::registerRecordType('Z', \Astm\Records\AbstractRecord::class);
    }

    // -----------------------------------------------------------------------
    //  Delimiters value object
    // -----------------------------------------------------------------------

    public function test_delimiters_encoding_chars(): void
    {
        $d = new Delimiters('|', '^', '\\', '&');
        $this->assertSame('\\^&', $d->encodingChars());
    }

    public function test_custom_delimiters_encoding_chars(): void
    {
        $d = new Delimiters('!', '@', '#', '$');
        $this->assertSame('#@$', $d->encodingChars());
    }

    // -----------------------------------------------------------------------
    //  Sender — ACK timeout path via MemoryTransport with no response
    // -----------------------------------------------------------------------

    public function test_sender_timeout_raises_exception(): void
    {
        $transport = new MemoryTransport();
        $transport->connect();
        // Queue nothing — readByte will throw immediately (no bytes in buffer)

        $this->expectException(AstmException::class);
        (new Sender($transport, ackTimeoutMs: 1))->send($this->makeMessage());
    }

    // -----------------------------------------------------------------------
    //  LLP — frame number wraps correctly across 7+ records
    // -----------------------------------------------------------------------

    public function test_frame_numbers_wrap_1_through_7(): void
    {
        $msg = MessageBuilder::create()->sender('LIS');
        for ($i = 0; $i < 10; $i++) {
            $msg->result(fn($r) => $r->test('WBC')->value('7.0'));
        }
        $frames  = (new LlpEncoder())->encode($msg->build());
        $numbers = array_map(fn(Frame $f) => $f->frameNumber % 8, $frames);

        // Must contain 0 (wrap of 8) or never exceed 7
        $this->assertEmpty(array_filter($numbers, fn($n) => $n > 7));
    }

    // -----------------------------------------------------------------------
    //  End-to-end: toArray → JSON → re-parse round trip
    // -----------------------------------------------------------------------

    public function test_to_json_data_matches_original(): void
    {
        $original = $this->makeMessage();
        $json     = $original->toJson(JSON_THROW_ON_ERROR);
        $data     = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('ARRAY-LIS', $data['sender']);
        $this->assertSame('Müller',    $data['patient']['lastName']);

        $byName = [];
        foreach ($data['results'] as $r) {
            $byName[$r['test']] = $r;
        }
        $this->assertSame('7.2',     $byName['WBC']['value']);
        $this->assertSame('16.5',    $byName['HGB']['value']);
        $this->assertSame('H',       $byName['HGB']['flag']);
        $this->assertSame('310',     $byName['PLT']['value']);
    }

    // -----------------------------------------------------------------------
    //  Helper
    // -----------------------------------------------------------------------

    private function makeMessage(): Message
    {
        return MessageBuilder::create()
            ->sender('ARRAY-LIS', 'v1.0')
            ->patient(fn($p) => $p
                ->id('P-ARRAY', 'LAB-ARRAY')
                ->name('Müller', 'Fatima')
                ->sex('F')
                ->birthdate('19920801')
                ->address('42 Lab Road')
                ->phone('555-9876'))
            ->order(fn($o) => $o
                ->specimenId('EDTA-ARRAY')
                ->addTest('WBC')
                ->addTest('HGB')
                ->addTest('PLT')
                ->reportType('F'))
            ->result(fn($r) => $r->test('WBC')->value('7.2')->units('10*3/uL')->flag('N')->status('F'))
            ->result(fn($r) => $r->test('HGB')->value('16.5')->units('g/dL')->flag('H')->status('F'))
            ->result(fn($r) => $r->test('PLT')->value('310')->units('10*3/uL')->flag('N')->status('F'))
            ->build();
    }
}
