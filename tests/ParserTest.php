<?php

declare(strict_types=1);

namespace Astm\Tests;

use Astm\Delimiters;
use Astm\Parser;
use Astm\Records\Comment;
use Astm\Records\Header;
use Astm\Records\Order;
use Astm\Records\Patient;
use Astm\Records\Result;
use Astm\Records\Terminator;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    private static string $sample = <<<'ASTM'
H|\^&|||    XN-350^00-14^13126^^^^AW618382||||||||E1394-97
P|1||||^^|||U|||||^||||||||||||^^^
C|1||
O|1||^^            5411330421^M|^^^^WBC\^^^^RBC\^^^^HGB\^^^^HCT\^^^^MCV\^^^^MCH\^^^^MCHC\^^^^PLT\^^^^RDW-SD\^^^^RDW-CV\^^^^PDW\^^^^MPV\^^^^P-LCR\^^^^PCT\^^^^NEUT#\^^^^LYMPH#\^^^^MONO#\^^^^EO#\^^^^BASO#\^^^^NEUT%\^^^^LYMPH%\^^^^MONO%\^^^^EO%\^^^^BASO%\^^^^IG#\^^^^IG%\^^^^MICROR\^^^^MACROR\^^^^OPEN|||||||N||||||||||||||F
C|1||
R|1|^^^^WBC^1|9.24|10*3/uL||N||F||||20250329143330
R|2|^^^^RBC^1|5.34|10*6/uL||N||F||||20250329143330
R|3|^^^^HGB^1|10.1|g/dL||N||F||||20250329143330
R|4|^^^^HCT^1|33.7|%||N||F||||20250329143330
R|5|^^^^MCV^1|63.1|fL||L||F||||20250329143330
R|6|^^^^MCH^1|18.9|pg||L||F||||20250329143330
R|7|^^^^MCHC^1|30.0|g/dL||L||F||||20250329143330
R|8|^^^^PLT^1|396|10*3/uL||N||F||||20250329143330
R|9|^^^^NEUT%^1|52.9|%||W||F||||20250329143330
R|10|^^^^LYMPH%^1|38.0|%||W||F||||20250329143330
R|11|^^^^MONO%^1|7.5|%||W||F||||20250329143330
R|12|^^^^EO%^1|0.8|%||N||F||||20250329143330
R|13|^^^^BASO%^1|0.8|%||N||F||||20250329143330
R|14|^^^^NEUT#^1|4.90|10*3/uL||W||F||||20250329143330
R|15|^^^^LYMPH#^1|3.51|10*3/uL||W||F||||20250329143330
R|16|^^^^MONO#^1|0.69|10*3/uL||W||F||||20250329143330
R|17|^^^^EO#^1|0.07|10*3/uL||N||F||||20250329143330
R|18|^^^^BASO#^1|0.07|10*3/uL||N||F||||20250329143330
R|19|^^^^IG%^1|0.1|%||W||F||||20250329143330
R|20|^^^^IG#^1|0.01|10*3/uL||W||F||||20250329143330
R|21|^^^^RDW-SD^1|43.9|fL||N||F||||20250329143330
R|22|^^^^RDW-CV^1|20.5|%||H||F||||20250329143330
R|23|^^^^MICROR^1|46.5|%||N||F||||20250329143330
R|24|^^^^MACROR^1|2.2|%||N||F||||20250329143330
R|25|^^^^PDW^1|11.0|fL||W||F||||20250329143330
R|26|^^^^MPV^1|9.1|fL||W||F||||20250329143330
R|27|^^^^P-LCR^1|18.5|%||W||F||||20250329143330
R|28|^^^^PCT^1|0.36|%||W||F||||20250329143330
R|29|^^^^Anisocytosis||||A||F||||20250329143330
R|32|^^^^Blasts/Abn_Lympho?|110|||A||F||||20250329143330
R|42|^^^^Positive_Morph||||A||F||||20250329143330
R|43|^^^^SCAT_WDF|PNG&R&20250329&R&2025_03_29_14_33_5411330421_WDF.PNG|||N||F||||20250329143330
C|1||
L|
ASTM;

    // -----------------------------------------------------------------------
    //  Delimiter tests
    // -----------------------------------------------------------------------

    public function test_delimiters_parsed_from_header(): void
    {
        $d = Delimiters::fromHeaderLine('H|\^&|||rest');

        $this->assertSame('|',  $d->field);
        $this->assertSame('^',  $d->component);
        $this->assertSame('\\', $d->repeat);
        $this->assertSame('&',  $d->escape);
    }

    // -----------------------------------------------------------------------
    //  Header record
    // -----------------------------------------------------------------------

    public function test_header_record_parsed(): void
    {
        $msg    = (new Parser())->parse(self::$sample);
        $header = $msg->getHeader();

        $this->assertInstanceOf(Header::class, $header);
        $this->assertSame('E1394-97', $header->getVersionNumber());
    }

    public function test_header_sender_name(): void
    {
        $header = (new Parser())->parse(self::$sample)->getHeader();
        $this->assertSame('XN-350', $header->getSenderName());
    }

    // -----------------------------------------------------------------------
    //  Patient record
    // -----------------------------------------------------------------------

    public function test_patient_record_parsed(): void
    {
        $msg     = (new Parser())->parse(self::$sample);
        $patient = $msg->getFirstPatient();

        $this->assertInstanceOf(Patient::class, $patient);
        $this->assertSame('1', $patient->getSequenceNumber());
        $this->assertSame('U', $patient->getSex());
    }

    // -----------------------------------------------------------------------
    //  Order record
    // -----------------------------------------------------------------------

    public function test_order_record_parsed(): void
    {
        $msg   = (new Parser())->parse(self::$sample);
        $order = $msg->getFirstOrder();

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame('1', $order->getSequenceNumber());
        $this->assertSame('F', $order->getReportType());
    }

    public function test_order_contains_multiple_test_ids(): void
    {
        $order = (new Parser())->parse(self::$sample)->getFirstOrder();
        $tests = $order->getUniversalTestIds();

        // The sample O record repeats 28 test IDs separated by "\"
        $this->assertGreaterThan(5, count($tests));
    }

    // -----------------------------------------------------------------------
    //  Result records
    // -----------------------------------------------------------------------

    public function test_results_count(): void
    {
        $results = (new Parser())->parse(self::$sample)->getResults();
        $this->assertCount(32, $results);
    }

    public function test_wbc_result(): void
    {
        $results = (new Parser())->parse(self::$sample)->getResults();
        $wbc     = $results[0];

        $this->assertInstanceOf(Result::class, $wbc);
        $this->assertSame('WBC',      $wbc->getTestName());
        $this->assertSame('9.24',     $wbc->getValue());
        $this->assertSame('10*3/uL',  $wbc->getUnits());
        $this->assertSame('N',        $wbc->getAbnormalFlag());
        $this->assertSame('F',        $wbc->getResultStatus());
        $this->assertTrue($wbc->isNormal());
        $this->assertTrue($wbc->isFinal());
        $this->assertEqualsWithDelta(9.24, $wbc->getNumericValue(), 0.001);
    }

    public function test_mcv_flagged_low(): void
    {
        $map = (new Parser())->parse(self::$sample)->getResultMap();
        $this->assertSame('L', $map['MCV']['flag']);
    }

    public function test_rdw_cv_flagged_high(): void
    {
        $map = (new Parser())->parse(self::$sample)->getResultMap();
        $this->assertSame('H', $map['RDW-CV']['flag']);
    }

    public function test_result_map_keys(): void
    {
        $map = (new Parser())->parse(self::$sample)->getResultMap();

        foreach (['WBC', 'RBC', 'HGB', 'HCT', 'PLT', 'NEUT%', 'LYMPH%'] as $test) {
            $this->assertArrayHasKey($test, $map, "Expected test '{$test}' in result map.");
        }
    }

    public function test_completed_datetime_parsed(): void
    {
        $results = (new Parser())->parse(self::$sample)->getResults();
        $dt      = $results[0]->getCompletedDateTimeObject();

        $this->assertNotNull($dt);
        $this->assertSame('2025-03-29', $dt->format('Y-m-d'));
        $this->assertSame('14:33:30',   $dt->format('H:i:s'));
    }

    // -----------------------------------------------------------------------
    //  Comment records
    // -----------------------------------------------------------------------

    public function test_comment_records_parsed(): void
    {
        $comments = (new Parser())->parse(self::$sample)->getComments();
        $this->assertGreaterThanOrEqual(1, count($comments));
        $this->assertInstanceOf(Comment::class, $comments[0]);
    }

    // -----------------------------------------------------------------------
    //  Terminator record
    // -----------------------------------------------------------------------

    public function test_terminator_record_parsed(): void
    {
        $term = (new Parser())->parse(self::$sample)->getTerminator();
        $this->assertInstanceOf(Terminator::class, $term);
        $this->assertTrue($term->isNormalTermination());
    }

    // -----------------------------------------------------------------------
    //  Round-trip serialisation
    // -----------------------------------------------------------------------

    public function test_record_serialises_back_to_original_line(): void
    {
        $msg     = (new Parser())->parse(self::$sample);
        $results = $msg->getResults();

        // The first R record rendered should start with "R|1|"
        $this->assertStringStartsWith('R|1|', $results[0]->toString());
    }
}
