<?php

declare(strict_types=1);

namespace Astm\Tests;

use Astm\Astm;
use Astm\DateTimeHelper;
use Astm\HeaderBuilder;
use Astm\Message;
use Astm\MessageBuilder;
use Astm\MessageCollection;
use Astm\MessageValidator;
use Astm\Parser;
use Astm\Records\Header;
use PHPUnit\Framework\TestCase;

final class CompletionTest extends TestCase
{
    // -----------------------------------------------------------------------
    //  HeaderBuilder – all fields
    // -----------------------------------------------------------------------

    public function test_header_builder_sender_all_components(): void
    {
        $msg = MessageBuilder::create()
            ->header(fn (HeaderBuilder $h) => $h
                ->sender('XN-350', '00-14', '3.1.2', 'SN99999', 'CFG-A')
                ->receiverId('MY-LIS')
                ->processingId('P'))
            ->build();

        $header = $msg->getHeader();
        $this->assertSame('XN-350', $header->getSenderName());
        $this->assertSame('00-14',  $header->getSenderId());
        $this->assertSame('3.1.2',  $header->getSenderVersion());
        $this->assertSame('MY-LIS', $header->getField(10));
        $this->assertSame('P',      $header->getProcessingId());
    }

    public function test_header_builder_individual_setters(): void
    {
        $msg = MessageBuilder::create()
            ->header(fn (HeaderBuilder $h) => $h
                ->senderName('MY-INSTRUMENT')
                ->senderId('STATION-1')
                ->senderVersion('v2.5')
                ->serialNumber('SN12345')
                ->receiverId('LIS-PROD')
                ->processingId('T')
                ->messageControlId('CTL-001')
                ->senderAddress('Lab, 3rd Floor')
                ->senderPhone('+234-800-000-0001')
                ->comment('Config A'))
            ->build();

        $h = $msg->getHeader();
        $this->assertSame('MY-INSTRUMENT', $h->getSenderName());
        $this->assertSame('STATION-1',     $h->getSenderId());
        $this->assertSame('v2.5',          $h->getSenderVersion());
        $this->assertSame('LIS-PROD',      $h->getField(10));
        $this->assertSame('T',             $h->getProcessingId());
        $this->assertSame('CTL-001',       $h->getField(3));
    }

    public function test_header_builder_timestamp_override(): void
    {
        $fixed = new \DateTimeImmutable('2025-01-15 08:30:00');
        $msg   = MessageBuilder::create()
            ->header(fn (HeaderBuilder $h) => $h
                ->senderName('LIS')
                ->messageDateTimeObject($fixed))
            ->build();

        $this->assertSame('20250115083000', $msg->getHeader()->getMessageDateTime());
    }

    public function test_header_builder_timestamp_string(): void
    {
        $msg = MessageBuilder::create()
            ->header(fn (HeaderBuilder $h) => $h
                ->senderName('LIS')
                ->messageDateTime('20250401120000'))
            ->build();

        $this->assertSame('20250401120000', $msg->getHeader()->getMessageDateTime());
    }

    public function test_header_builder_accessible_via_astm_facade(): void
    {
        $msg = Astm::build()
            ->header(fn (HeaderBuilder $h) => $h
                ->sender('HAEM-9000', 'ST-01', 'v4.0')
                ->processingId('P'))
            ->result(fn ($r) => $r->test('WBC')->value('7.0'))
            ->build();

        $this->assertSame('HAEM-9000', $msg->getHeader()->getSenderName());
        $this->assertSame('ST-01',     $msg->getHeader()->getSenderId());
        $this->assertSame('v4.0',      $msg->getHeader()->getSenderVersion());
    }

    public function test_header_builder_and_sender_are_independent(): void
    {
        // sender() shorthand
        $m1 = MessageBuilder::create()->sender('LIS-A', 'v1.0')->build();
        // header() full builder
        $m2 = MessageBuilder::create()
            ->header(fn ($h) => $h->senderName('LIS-B')->senderVersion('v2.0'))
            ->build();

        $this->assertSame('LIS-A', $m1->getHeader()->getSenderName());
        $this->assertSame('LIS-B', $m2->getHeader()->getSenderName());
    }

    public function test_header_builder_default_processing_id_is_P(): void
    {
        $msg = MessageBuilder::create()
            ->header(fn ($h) => $h->senderName('LIS'))
            ->build();

        $this->assertSame('P', $msg->getHeader()->getProcessingId());
    }

    public function test_header_builder_default_version_is_E1394_97(): void
    {
        $msg = MessageBuilder::create()
            ->header(fn ($h) => $h->senderName('LIS'))
            ->build();

        $this->assertSame('E1394-97', $msg->getHeader()->getVersionNumber());
    }

    // -----------------------------------------------------------------------
    //  DateTimeHelper wired into record Object() methods
    // -----------------------------------------------------------------------

    public function test_header_get_message_datetime_object(): void
    {
        $msg = MessageBuilder::create()
            ->header(fn ($h) => $h->senderName('LIS')->messageDateTime('20250329143330'))
            ->build();

        $dt = $msg->getHeader()->getMessageDateTimeObject();
        $this->assertNotNull($dt);
        $this->assertSame('2025-03-29', $dt->format('Y-m-d'));
        $this->assertSame('14:33:30',   $dt->format('H:i:s'));
    }

    public function test_patient_get_birthdate_object(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->patient(fn ($p) => $p->id('P1')->name('Doe', 'Jane')->sex('F')->birthdate('19920801'))
            ->build();

        $dt = $msg->getFirstPatient()->getBirthdateObject();
        $this->assertNotNull($dt);
        $this->assertSame('1992-08-01', $dt->format('Y-m-d'));
    }

    public function test_result_get_completed_datetime_object(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r->test('WBC')->value('7.0')->completedAt('20250401143022'))
            ->build();

        $dt = $msg->getResults()[0]->getCompletedDateTimeObject();
        $this->assertNotNull($dt);
        $this->assertSame('2025-04-01', $dt->format('Y-m-d'));
        $this->assertSame('14:30:22',   $dt->format('H:i:s'));
    }

    public function test_object_methods_return_null_on_empty_field(): void
    {
        // Build a header with an explicitly empty datetime field
        $msg = MessageBuilder::create()
            ->header(fn ($h) => $h->senderName('LIS')->messageDateTime(''))
            ->build();
        $this->assertNull($msg->getHeader()->getMessageDateTimeObject());
    }

    // -----------------------------------------------------------------------
    //  MessageValidator – group-scoped sequence numbers
    // -----------------------------------------------------------------------

    public function test_validator_accepts_c_records_resetting_per_group(): void
    {
        // This mirrors real XN-350 behaviour: C|1| appears after P, O, and after R records
        $raw = implode("\n", [
            'H|\\^&|||XN-350||||||||E1394-97',
            'P|1',
            'C|1||Comment on patient',
            'O|1||S-001|^^^^WBC|||||||N||||||||||||||F',
            'C|1||Comment on order',
            'R|1|^^^^WBC^1|7.2|10*3/uL||N||F||||20250329143330',
            'R|2|^^^^HGB^1|14.0|g/dL||N||F||||20250329143330',
            'C|1||Comment after results',
            'L|1|N',
        ]);

        $msg    = (new Parser())->parse($raw);
        $v      = new MessageValidator();
        $errors = [];
        $valid  = $v->validate($msg);

        $this->assertTrue($valid, 'Errors: ' . implode('; ', $v->getErrors()));
    }

    public function test_validator_accepts_r_records_resetting_per_order(): void
    {
        // Two O records each with R records starting from seq 1
        $raw = implode("\n", [
            'H|\\^&|||LIS||||||||E1394-97',
            'P|1',
            'O|1||S-001|^^^^WBC|||||||N||||||||||||||F',
            'R|1|^^^^WBC^1|7.2|10*3/uL||N||F||||20250329143330',
            'O|2||S-002|^^^^HGB|||||||N||||||||||||||F',
            'R|1|^^^^HGB^1|14.0|g/dL||N||F||||20250329143330',
            'L|1|N',
        ]);

        $msg   = (new Parser())->parse($raw);
        $v     = new MessageValidator();
        $valid = $v->validate($msg);

        $this->assertTrue($valid, 'Errors: ' . implode('; ', $v->getErrors()));
    }

    public function test_validator_real_xn350_file_is_valid(): void
    {
        $path = '/mnt/user-data/uploads/sample_astm_E1394-97.txt';
        if (!file_exists($path)) {
            $this->markTestSkipped('Sample file not available.');
        }

        $msg   = Astm::parse((string) file_get_contents($path));
        $v     = new MessageValidator();
        $valid = $v->validate($msg);

        $this->assertTrue($valid, 'Errors: ' . implode('; ', $v->getErrors()));
    }

    public function test_validator_wrong_global_seq_still_fails(): void
    {
        // P records have global sequence — P|2| as first P should fail
        $raw = implode("\n", [
            'H|\\^&|||LIS||||||||E1394-97',
            'P|2',   // wrong — should be 1
            'L|1|N',
        ]);

        $msg = (new Parser())->parse($raw);
        $v   = new MessageValidator();
        $this->assertFalse($v->validate($msg));
        $this->assertStringContainsString('sequence number', $v->getErrors()[0]);
    }

    // -----------------------------------------------------------------------
    //  MessageCollection::toFile()
    // -----------------------------------------------------------------------

    public function test_collection_to_file_writes_all_sessions(): void
    {
        $msgs = [
            MessageBuilder::create()->sender('LIS')->result(fn ($r) => $r->test('WBC')->value('7.0'))->build(),
            MessageBuilder::create()->sender('LIS')->result(fn ($r) => $r->test('HGB')->value('14.0'))->build(),
        ];
        $coll = MessageCollection::fromMessages($msgs);
        $path = sys_get_temp_dir() . '/astm_coll_' . uniqid() . '.astm';

        $coll->toFile($path, "\n");

        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertStringContainsString('H|', $content);
        $this->assertStringContainsString('WBC', $content);
        $this->assertStringContainsString('HGB', $content);

        unlink($path);
    }

    public function test_collection_to_file_round_trips(): void
    {
        $original = MessageCollection::fromMessages([
            MessageBuilder::create()->sender('LIS-RT')
                ->result(fn ($r) => $r->test('PLT')->value('350')->units('10*3/uL')->flag('N'))
                ->build(),
        ]);

        $path = sys_get_temp_dir() . '/astm_rt_' . uniqid() . '.astm';
        $original->toFile($path, "\n");

        $recovered = MessageCollection::fromFile($path);
        $this->assertCount(1, $recovered);
        $this->assertSame('LIS-RT', $recovered->first()->getHeader()->getSenderName());
        $this->assertSame('350', $recovered->first()->getResultMap()['PLT']['value']);

        unlink($path);
    }

    // -----------------------------------------------------------------------
    //  bin/astm CLI – integration
    // -----------------------------------------------------------------------

    public function test_cli_parse_exits_0_on_valid_file(): void
    {
        $path = sys_get_temp_dir() . '/astm_cli_' . uniqid() . '.astm';
        $msg  = MessageBuilder::create()->sender('CLI-TEST')
            ->result(fn ($r) => $r->test('WBC')->value('7.2')->units('10*3/uL')->flag('N'))
            ->build();
        file_put_contents($path, $msg->toString("\n"));

        $output = [];
        $code   = 0;
        exec('php ' . escapeshellarg(__DIR__ . '/../bin/astm') . ' parse ' . escapeshellarg($path) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code);
        $out = implode("\n", $output);
        $this->assertStringContainsString('CLI-TEST', $out);
        $this->assertStringContainsString('WBC', $out);

        unlink($path);
    }

    public function test_cli_verify_exits_0_on_valid_file(): void
    {
        $path = sys_get_temp_dir() . '/astm_verify_' . uniqid() . '.astm';
        $msg  = MessageBuilder::create()->sender('LIS')
            ->result(fn ($r) => $r->test('WBC')->value('7.2'))
            ->build();
        file_put_contents($path, $msg->toString("\n"));

        $code = 0;
        exec('php ' . escapeshellarg(__DIR__ . '/../bin/astm') . ' verify ' . escapeshellarg($path) . ' 2>&1', $_, $code);
        $this->assertSame(0, $code);

        unlink($path);
    }

    public function test_cli_json_outputs_valid_json(): void
    {
        $path = sys_get_temp_dir() . '/astm_json_' . uniqid() . '.astm';
        $msg  = MessageBuilder::create()->sender('JSON-LIS')
            ->result(fn ($r) => $r->test('HGB')->value('14.5')->units('g/dL')->flag('N'))
            ->build();
        file_put_contents($path, $msg->toString("\n"));

        $output = [];
        $code   = 0;
        exec('php ' . escapeshellarg(__DIR__ . '/../bin/astm') . ' json ' . escapeshellarg($path) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code);
        $json = implode("\n", $output);
        $data = json_decode($json, true);
        $this->assertIsArray($data);
        $this->assertSame('JSON-LIS', $data['sender']);

        unlink($path);
    }

    public function test_cli_summary_exits_0(): void
    {
        $path = sys_get_temp_dir() . '/astm_sum_' . uniqid() . '.astm';
        $msg  = MessageBuilder::create()->sender('SUM-LIS')
            ->result(fn ($r) => $r->test('WBC')->value('7.2'))
            ->result(fn ($r) => $r->test('HGB')->value('16.5')->flag('H'))
            ->build();
        file_put_contents($path, $msg->toString("\n"));

        $output = [];
        $code   = 0;
        exec('php ' . escapeshellarg(__DIR__ . '/../bin/astm') . ' summary ' . escapeshellarg($path) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code);
        $out = implode("\n", $output);
        $this->assertStringContainsString('Sessions', $out);
        $this->assertStringContainsString('Abnormal', $out);

        unlink($path);
    }

    public function test_cli_help_exits_0(): void
    {
        $code = 0;
        exec('php ' . escapeshellarg(__DIR__ . '/../bin/astm') . ' help 2>&1', $_, $code);
        $this->assertSame(0, $code);
    }

    public function test_cli_missing_file_exits_nonzero(): void
    {
        $code = 0;
        exec('php ' . escapeshellarg(__DIR__ . '/../bin/astm') . ' parse /nonexistent/path.astm 2>&1', $_, $code);
        $this->assertNotSame(0, $code);
    }

    public function test_cli_unknown_command_exits_nonzero(): void
    {
        $code = 0;
        exec('php ' . escapeshellarg(__DIR__ . '/../bin/astm') . ' badcommand 2>&1', $_, $code);
        $this->assertNotSame(0, $code);
    }

    // -----------------------------------------------------------------------
    //  CLI decode command (LLP binary)
    // -----------------------------------------------------------------------

    public function test_cli_decode_llp_binary_file(): void
    {
        $binPath = sys_get_temp_dir() . '/astm_decode_' . uniqid() . '.bin';
        $msg     = MessageBuilder::create()->sender('BIN-LIS')
            ->result(fn ($r) => $r->test('WBC')->value('6.5'))
            ->build();
        Astm::writeFile($msg, $binPath);

        $output = [];
        $code   = 0;
        exec('php ' . escapeshellarg(__DIR__ . '/../bin/astm') . ' decode ' . escapeshellarg($binPath) . ' 2>&1', $output, $code);

        $this->assertSame(0, $code);
        $out = implode("\n", $output);
        $this->assertStringContainsString('BIN-LIS', $out);

        unlink($binPath);
    }

    // -----------------------------------------------------------------------
    //  composer.json bin entry
    // -----------------------------------------------------------------------

    public function test_composer_json_has_bin_entry(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertArrayHasKey('bin', $composer);
        $this->assertContains('bin/astm', $composer['bin']);
    }

    public function test_composer_json_name_is_correct(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertSame('orderlyconnect/astm-e1394-91', $composer['name']);
    }

    // -----------------------------------------------------------------------
    //  Full real-file parse + summary verification
    // -----------------------------------------------------------------------

    public function test_real_xn350_parse_and_summary(): void
    {
        $path = '/mnt/user-data/uploads/sample_astm_E1394-97.txt';
        if (!file_exists($path)) {
            $this->markTestSkipped('Sample file not available.');
        }

        $coll = Astm::parseFile($path);
        $this->assertCount(1, $coll);

        $msg = $coll->first();
        $this->assertSame('XN-350', $msg->getHeader()->getSenderName());
        $this->assertSame('E1394-97', $msg->getHeader()->getVersionNumber());

        // Validate
        $errors = Astm::validate($msg);
        $this->assertEmpty($errors, 'Errors: ' . implode('; ', $errors));

        // Collection cross-queries
        $abnormal = $coll->getAbnormalResults();
        $this->assertNotEmpty($abnormal);

        $wbcResults = $coll->getResultsByTest('WBC');
        $this->assertCount(1, $wbcResults);
        $this->assertSame('9.24', $wbcResults[0]->getValue());
    }
}
