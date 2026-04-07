#!/usr/bin/env php
<?php

/**
 * Example 4 — Batch-parse a file containing multiple ASTM sessions and
 *             produce a summary report using MessageCollection.
 *
 * Usage:
 *   php examples/04_batch_collection.php [path/to/batch.astm]
 *
 * Without an argument the script generates a synthetic batch in memory.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Astm\Astm;
use Astm\MessageBuilder;
use Astm\MessageCollection;

// ── Build a synthetic batch when no file is provided ──────────────────────
$path = $argv[1] ?? null;

if ($path !== null && file_exists($path)) {
    $collection = MessageCollection::fromFile($path);
} else {
    echo "(No file supplied — using synthetic batch of 5 messages.)\n\n";

    $patients = [
        ['Nwachukwu', 'Emeka',  'M', '9.24', '10.1', 'N', 'N'],
        ['Diallo',    'Aissata','F', '3.10', '8.9',  'L', 'L'],   // low WBC + HGB
        ['Osei',      'Kwame',  'M', '7.50', '15.2', 'N', 'N'],
        ['Mensah',    'Akosua', 'F', '15.2', '11.8', 'H', 'L'],   // high WBC, low HGB
        ['Ibrahim',   'Fatima', 'F', '6.80', '13.1', 'N', 'N'],
    ];

    $messages = [];
    foreach ($patients as $i => [$last, $first, $sex, $wbc, $hgb, $wbcFlag, $hgbFlag]) {
        $messages[] = MessageBuilder::create()
            ->sender('XN-350', 'v3.0')
            ->patient(fn ($p) => $p->id("P-{$i}")->name($last, $first)->sex($sex))
            ->order(fn ($o) => $o->specimenId("EDTA-{$i}")->addTest('WBC')->addTest('HGB')->reportType('F'))
            ->result(fn ($r) => $r->test('WBC')->value($wbc)->units('10*3/uL')->flag($wbcFlag))
            ->result(fn ($r) => $r->test('HGB')->value($hgb)->units('g/dL')->flag($hgbFlag))
            ->build();
    }
    $collection = MessageCollection::fromMessages($messages);
}

// ── Summary report ────────────────────────────────────────────────────────
$total    = count($collection);
$abnormal = $collection->getAbnormalResults();
$flagged  = $collection->getMessagesWithAbnormalities();

echo "Batch Report\n";
echo "════════════\n";
printf("Sessions parsed  : %d\n", $total);
printf("Abnormal results : %d\n", count($abnormal));
printf("Flagged patients : %d\n", count($flagged));
echo "\n";

// ── Per-test summary ──────────────────────────────────────────────────────
echo "Per-test summary\n";
echo str_repeat('─', 60) . "\n";
printf("%-14s  %8s  %8s  %8s  %8s\n", 'Test', 'Count', 'Min', 'Max', 'Mean');
echo str_repeat('─', 60) . "\n";

$mapped = $collection->getAllResultsMapped();
foreach ($mapped as $testName => $entries) {
    $numerics = array_filter(
        array_map(fn ($e) => is_numeric($e['value']) ? (float) $e['value'] : null, $entries),
        fn ($v) => $v !== null,
    );
    if (empty($numerics)) {
        continue;
    }
    printf("%-14s  %8d  %8.2f  %8.2f  %8.2f\n",
        $testName,
        count($numerics),
        min($numerics),
        max($numerics),
        array_sum($numerics) / count($numerics),
    );
}

// ── Flagged patients detail ────────────────────────────────────────────────
if (!empty($flagged)) {
    echo "\nFlagged patients:\n";
    echo str_repeat('─', 60) . "\n";
    foreach ($flagged as $msg) {
        $p = $msg->getFirstPatient();
        $name = $p ? trim($p->getLastName() . ' ' . $p->getFirstName()) : 'Unknown';
        echo "  {$name}\n";
        foreach (array_filter($msg->getResults(), fn ($r) => $r->isAbnormal()) as $r) {
            printf("    %-10s  %6s %-10s  [%s]\n",
                $r->getTestName(), $r->getValue(),
                $r->getUnits(), $r->getAbnormalFlag());
        }
    }
}
