<?php

declare(strict_types=1);

namespace Astm\Tests;

use Astm\Astm;
use Astm\DateTimeHelper;
use Astm\Delimiters;
use Astm\Message;
use Astm\MessageBuilder;
use Astm\MessageDiff;
use Astm\Records\AbstractRecord;
use PHPUnit\Framework\TestCase;

final class FinalFeatureTest extends TestCase
{
    // -----------------------------------------------------------------------
    //  DateTimeHelper – parse
    // -----------------------------------------------------------------------

    public function test_parse_full_timestamp(): void
    {
        $dt = DateTimeHelper::parse('20250329143330');
        $this->assertNotNull($dt);
        $this->assertSame('2025-03-29', $dt->format('Y-m-d'));
        $this->assertSame('14:33:30',   $dt->format('H:i:s'));
    }

    public function test_parse_date_only(): void
    {
        $dt = DateTimeHelper::parse('19920801');
        $this->assertNotNull($dt);
        $this->assertSame('1992-08-01', $dt->format('Y-m-d'));
    }

    public function test_parse_twelve_char_timestamp(): void
    {
        $dt = DateTimeHelper::parse('202503291433');
        $this->assertNotNull($dt);
        $this->assertSame('2025-03-29', $dt->format('Y-m-d'));
        $this->assertSame('14:33',      $dt->format('H:i'));
    }

    public function test_parse_empty_returns_null(): void
    {
        $this->assertNull(DateTimeHelper::parse(''));
        $this->assertNull(DateTimeHelper::parse(null));
        $this->assertNull(DateTimeHelper::parse('   '));
    }

    public function test_parse_date_trims_whitespace(): void
    {
        $dt = DateTimeHelper::parse('  20250101  ');
        $this->assertNotNull($dt);
        $this->assertSame('2025-01-01', $dt->format('Y-m-d'));
    }

    public function test_parse_date_helper(): void
    {
        $dt = DateTimeHelper::parseDate('19900415');
        $this->assertNotNull($dt);
        $this->assertSame('1990-04-15', $dt->format('Y-m-d'));
    }

    public function test_parse_date_extracts_first_8_chars(): void
    {
        $dt = DateTimeHelper::parseDate('20251225120000');
        $this->assertNotNull($dt);
        $this->assertSame('2025-12-25', $dt->format('Y-m-d'));
    }

    // -----------------------------------------------------------------------
    //  DateTimeHelper – format
    // -----------------------------------------------------------------------

    public function test_format_datetime(): void
    {
        $dt  = new \DateTimeImmutable('2025-03-29 14:33:30');
        $str = DateTimeHelper::format($dt);
        $this->assertSame('20250329143330', $str);
    }

    public function test_format_date(): void
    {
        $dt  = new \DateTimeImmutable('1990-04-15');
        $str = DateTimeHelper::formatDate($dt);
        $this->assertSame('19900415', $str);
    }

    public function test_now_returns_14_char_string(): void
    {
        $now = DateTimeHelper::now();
        $this->assertMatchesRegularExpression('/^\d{14}$/', $now);
    }

    public function test_today_returns_8_char_string(): void
    {
        $today = DateTimeHelper::today();
        $this->assertMatchesRegularExpression('/^\d{8}$/', $today);
    }

    public function test_is_valid_true_for_valid_timestamp(): void
    {
        $this->assertTrue(DateTimeHelper::isValid('20250329143330'));
        $this->assertTrue(DateTimeHelper::isValid('19920801'));
    }

    public function test_is_valid_false_for_empty(): void
    {
        $this->assertFalse(DateTimeHelper::isValid(''));
        $this->assertFalse(DateTimeHelper::isValid(null));
    }

    // -----------------------------------------------------------------------
    //  DateTimeHelper via Astm facade
    // -----------------------------------------------------------------------

    public function test_facade_parse_datetime(): void
    {
        $dt = Astm::parseDateTime('20250329143330');
        $this->assertNotNull($dt);
        $this->assertSame('2025-03-29', $dt->format('Y-m-d'));
    }

    public function test_facade_format_datetime(): void
    {
        $dt  = new \DateTimeImmutable('2025-01-15 08:30:00');
        $str = Astm::formatDateTime($dt);
        $this->assertSame('20250115083000', $str);
    }

    public function test_facade_now_format(): void
    {
        $this->assertMatchesRegularExpression('/^\d{14}$/', Astm::now());
    }

    // -----------------------------------------------------------------------
    //  Message::getAbnormalResults()
    // -----------------------------------------------------------------------

    public function test_message_get_abnormal_results(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r->test('WBC')->value('7.2')->flag('N'))
            ->result(fn ($r) => $r->test('HGB')->value('8.0')->flag('L'))
            ->result(fn ($r) => $r->test('PLT')->value('500')->flag('H'))
            ->build();

        $abnormal = $msg->getAbnormalResults();
        $this->assertCount(2, $abnormal);
        $names = array_map(fn ($r) => $r->getTestName(), $abnormal);
        $this->assertContains('HGB', $names);
        $this->assertContains('PLT', $names);
        $this->assertNotContains('WBC', $names);
    }

    public function test_message_get_abnormal_results_empty_when_all_normal(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r->test('WBC')->value('7.2')->flag('N'))
            ->build();

        $this->assertEmpty($msg->getAbnormalResults());
    }

    // -----------------------------------------------------------------------
    //  Message::getFinalResults()
    // -----------------------------------------------------------------------

    public function test_message_get_final_results(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r->test('WBC')->value('7.2')->status('F'))
            ->result(fn ($r) => $r->test('HGB')->value('13.0')->status('P'))
            ->build();

        $final = $msg->getFinalResults();
        $this->assertCount(1, $final);
        $this->assertSame('WBC', $final[0]->getTestName());
    }

    // -----------------------------------------------------------------------
    //  Message::hasAbnormalities()
    // -----------------------------------------------------------------------

    public function test_has_abnormalities_true(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r->test('WBC')->value('15.0')->flag('H'))
            ->build();

        $this->assertTrue($msg->hasAbnormalities());
    }

    public function test_has_abnormalities_false(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r->test('WBC')->value('7.0')->flag('N'))
            ->build();

        $this->assertFalse($msg->hasAbnormalities());
    }

    // -----------------------------------------------------------------------
    //  ResultBuilder – reference range helpers
    // -----------------------------------------------------------------------

    public function test_reference_range_from_bounds(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r->test('WBC')->value('7.2')->referenceRangeFromBounds(4.0, 11.0))
            ->build();

        $this->assertSame('4-11', $msg->getResults()[0]->getReferenceRange());
    }

    public function test_reference_range_from_bounds_float(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r->test('Na')->value('142')->referenceRangeFromBounds(136.0, 145.0))
            ->build();

        $this->assertSame('136-145', $msg->getResults()[0]->getReferenceRange());
    }

    public function test_reference_range_from_bounds_string(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r->test('HGB')->value('13.5')->referenceRangeFromBounds('12.0', '16.0'))
            ->build();

        $this->assertSame('12.0-16.0', $msg->getResults()[0]->getReferenceRange());
    }

    public function test_reference_range_from_bounds_open_high(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r->test('X')->value('5')->referenceRangeFromBounds(null, 10))
            ->build();

        $this->assertSame('-10', $msg->getResults()[0]->getReferenceRange());
    }

    public function test_reference_range_limit_less_than(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r->test('PSA')->value('2.1')->referenceRangeLimit('<', 4.0))
            ->build();

        $this->assertSame('<4', $msg->getResults()[0]->getReferenceRange());
    }

    public function test_reference_range_limit_greater_than(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r->test('pH')->value('7.4')->referenceRangeLimit('>', 7.35))
            ->build();

        $this->assertSame('>7.35', $msg->getResults()[0]->getReferenceRange());
    }

    // -----------------------------------------------------------------------
    //  AbstractRecord::toArray()
    // -----------------------------------------------------------------------

    public function test_abstract_record_to_array(): void
    {
        $msg     = MessageBuilder::create()->sender('LIS')
            ->result(fn ($r) => $r->test('WBC')->value('7.2')->units('10*3/uL')->flag('N'))
            ->build();

        $result = $msg->getResults()[0];
        $arr    = $result->toArray();

        $this->assertIsArray($arr);
        $this->assertArrayHasKey(1, $arr);   // type
        $this->assertArrayHasKey(2, $arr);   // seq
        $this->assertArrayHasKey(3, $arr);   // universal test id
        $this->assertArrayHasKey(4, $arr);   // value
        $this->assertSame('R',    $arr[1]);
        $this->assertSame('1',    $arr[2]);
        $this->assertSame('7.2',  $arr[4]);
    }

    public function test_abstract_record_to_array_keys_are_1_based(): void
    {
        $msg    = MessageBuilder::create()->sender('LIS')->build();
        $header = $msg->getHeader();
        $arr    = $header->toArray();

        // Minimum key is 1
        $this->assertSame(1, min(array_keys($arr)));
        // No key 0
        $this->assertArrayNotHasKey(0, $arr);
    }

    // -----------------------------------------------------------------------
    //  MessageDiff – identical messages
    // -----------------------------------------------------------------------

    public function test_diff_identical_messages_has_no_differences(): void
    {
        $msg  = $this->buildMessage();
        $diff = MessageDiff::compare($msg, $msg);

        $this->assertFalse($diff->hasDifferences());
        $this->assertEmpty($diff->getFieldChanges());
        $this->assertEmpty($diff->getChangedResults());
    }

    // -----------------------------------------------------------------------
    //  MessageDiff – value changed
    // -----------------------------------------------------------------------

    public function test_diff_detects_value_change(): void
    {
        $parser    = new \Astm\Parser();
        $original  = $this->buildMessage();
        // Re-parse from string to get an independent copy
        $corrected = $parser->parse($original->toString("\n"));
        $corrected->getResults()[0]->setField(4, '99.9'); // WBC was 7.2

        $diff = MessageDiff::compare($original, $corrected);

        $this->assertTrue($diff->hasDifferences());

        $fieldChanges = $diff->getFieldChanges();
        $this->assertNotEmpty($fieldChanges);

        $changed = array_filter($fieldChanges, fn ($c) => $c['old'] === '7.2' && $c['new'] === '99.9');
        $this->assertNotEmpty($changed);
    }

    // -----------------------------------------------------------------------
    //  MessageDiff – result map change
    // -----------------------------------------------------------------------

    public function test_diff_changed_results_list(): void
    {
        $parser    = new \Astm\Parser();
        $original  = $this->buildMessage();
        $corrected = $parser->parse($original->toString("\n"));
        $corrected->getResults()[1]->setField(4, '20.0'); // HGB changed

        $diff    = MessageDiff::compare($original, $corrected);
        $changed = $diff->getChangedResults();

        $this->assertNotEmpty($changed);
        $this->assertContains('HGB', $diff->getChangedTestNames());
    }

    // -----------------------------------------------------------------------
    //  MessageDiff – added comment record
    // -----------------------------------------------------------------------

    public function test_diff_detects_added_comment(): void
    {
        $original  = $this->buildMessage();
        $corrected = MessageBuilder::fromMessage($original)
            ->comment('LIS annotation')
            ->build();

        $diff = MessageDiff::compare($original, $corrected);

        $this->assertTrue($diff->hasDifferences());
        $this->assertContains('C', $diff->getAddedTypes());
    }

    // -----------------------------------------------------------------------
    //  MessageDiff – getSummary()
    // -----------------------------------------------------------------------

    public function test_diff_summary_contains_change_description(): void
    {
        $parser    = new \Astm\Parser();
        $original  = $this->buildMessage();
        $corrected = $parser->parse($original->toString("\n"));
        $corrected->getResults()[0]->setField(4, '0.0');

        $diff    = MessageDiff::compare($original, $corrected);
        $summary = $diff->getSummary();

        $this->assertNotEmpty($summary);
        $found = array_filter($summary, fn ($l) => str_contains($l, '7.2'));
        $this->assertNotEmpty($found);
    }

    // -----------------------------------------------------------------------
    //  MessageDiff via Astm facade
    // -----------------------------------------------------------------------

    public function test_facade_diff(): void
    {
        $a    = $this->buildMessage();
        $b    = MessageBuilder::fromMessage($a)->build();
        $diff = Astm::diff($a, $b);

        $this->assertInstanceOf(MessageDiff::class, $diff);
        $this->assertFalse($diff->hasDifferences());
    }

    // -----------------------------------------------------------------------
    //  DateTimeHelper integration with built records
    // -----------------------------------------------------------------------

    public function test_result_completed_at_roundtrips_via_datetimehelper(): void
    {
        $ts  = '20250401143022';
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r->test('WBC')->value('7.0')->completedAt($ts))
            ->build();

        $raw = $msg->getResults()[0]->getCompletedDateTime();
        $dt  = DateTimeHelper::parse($raw);

        $this->assertNotNull($dt);
        $this->assertSame($ts, DateTimeHelper::format($dt));
    }

    public function test_header_datetime_parses_correctly(): void
    {
        $msg    = MessageBuilder::create()->sender('LIS')->build();
        $rawDt  = $msg->getHeader()->getMessageDateTime();

        $this->assertTrue(DateTimeHelper::isValid($rawDt), "Header timestamp '{$rawDt}' must be valid");
        $dt = DateTimeHelper::parse($rawDt);
        $this->assertNotNull($dt);
        // Must be a recent date (after 2020)
        $this->assertGreaterThan(2020, (int) $dt->format('Y'));
    }

    // -----------------------------------------------------------------------
    //  Edge cases
    // -----------------------------------------------------------------------

    public function test_diff_empty_messages_no_differences(): void
    {
        $a = new Message();
        $b = new Message();
        $diff = MessageDiff::compare($a, $b);
        $this->assertFalse($diff->hasDifferences());
    }

    public function test_message_get_abnormal_results_excludes_qualitative_empty_value(): void
    {
        // Qualitative flags (A) on records with no value should still count
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r->test('Anisocytosis')->value('')->flag('A'))
            ->result(fn ($r) => $r->test('WBC')->value('7.0')->flag('N'))
            ->build();

        $abnormal = $msg->getAbnormalResults();
        $this->assertCount(1, $abnormal);
        $this->assertSame('Anisocytosis', $abnormal[0]->getTestName());
    }

    public function test_result_builder_chaining_all_new_methods(): void
    {
        $msg = MessageBuilder::create()
            ->sender('LIS')
            ->result(fn ($r) => $r
                ->test('K')
                ->value('5.1')
                ->units('mmol/L')
                ->referenceRangeFromBounds(3.5, 5.0)
                ->flag('H')
                ->status('F')
                ->completedAt('20250401120000'))
            ->build();

        $result = $msg->getResults()[0];
        $this->assertSame('3.5-5', $result->getReferenceRange());
        $this->assertSame('H',     $result->getAbnormalFlag());
        $this->assertTrue($result->isHigh());
    }

    // -----------------------------------------------------------------------
    //  Helper
    // -----------------------------------------------------------------------

    private function buildMessage(): Message
    {
        return MessageBuilder::create()
            ->sender('DIFF-LIS', 'v1.0')
            ->patient(fn ($p) => $p->id('P001')->name('Test', 'Patient')->sex('M'))
            ->order(fn ($o) => $o->specimenId('S001')->addTest('WBC')->addTest('HGB'))
            ->result(fn ($r) => $r->test('WBC')->value('7.2')->units('10*3/uL')->flag('N'))
            ->result(fn ($r) => $r->test('HGB')->value('14.0')->units('g/dL')->flag('N'))
            ->build();
    }
}
