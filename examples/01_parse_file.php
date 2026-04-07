#!/usr/bin/env php
<?php

/**
 * Example 1 — Parse a plain-text ASTM file and display results.
 *
 * Usage:
 *   php examples/01_parse_file.php path/to/result.astm
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Astm\Astm;
use Astm\Records\Result;

$path = $argv[1] ?? null;

if ($path === null || !file_exists($path)) {
    $path = null;
    // Use the bundled sample when no file is supplied
    $raw = <<<'ASTM'
H|\^&|||XN-350^00-14^13126^^^^AW618382||||||||E1394-97
P|1||||^^|||U
O|1||^^5411330421^M|^^^^WBC\^^^^RBC\^^^^HGB\^^^^HCT|||||||N||||||||||||||F
R|1|^^^^WBC^1|9.24|10*3/uL||N||F||||20250329143330
R|2|^^^^RBC^1|5.34|10*6/uL||N||F||||20250329143330
R|3|^^^^HGB^1|10.1|g/dL||N||F||||20250329143330
R|4|^^^^HCT^1|33.7|%||N||F||||20250329143330
R|5|^^^^MCV^1|63.1|fL||L||F||||20250329143330
R|6|^^^^MCH^1|18.9|pg||L||F||||20250329143330
R|7|^^^^MCHC^1|30.0|g/dL||L||F||||20250329143330
R|8|^^^^PLT^1|396|10*3/uL||N||F||||20250329143330
L|1|N
ASTM;
} else {
    $raw = file_get_contents($path);
}

// ── Parse ─────────────────────────────────────────────────────────────────
$message = Astm::parse($raw);

// ── Validate ──────────────────────────────────────────────────────────────
$errors = Astm::validate($message);
if (!empty($errors)) {
    echo "⚠  Validation warnings:\n";
    foreach ($errors as $e) {
        echo "   - {$e}\n";
    }
    echo "\n";
}

// ── Header ────────────────────────────────────────────────────────────────
$header = $message->getHeader();
echo "┌─────────────────────────────────────────────────\n";
echo "│ Instrument : " . $header->getSenderName() . "\n";
echo "│ Version    : " . $header->getVersionNumber() . "\n";
if ($dt = $header->getMessageDateTimeObject()) {
    echo "│ Timestamp  : " . $dt->format('Y-m-d H:i:s') . "\n";
}
echo "└─────────────────────────────────────────────────\n\n";

// ── Patient ───────────────────────────────────────────────────────────────
if ($patient = $message->getFirstPatient()) {
    $name = trim($patient->getLastName() . ' ' . $patient->getFirstName());
    if ($name) {
        echo "Patient : {$name}  ({$patient->getSex()})\n\n";
    }
}

// ── Results ───────────────────────────────────────────────────────────────
printf("%-14s  %8s  %-12s  %s\n", 'Test', 'Value', 'Units', 'Flag');
echo str_repeat('─', 52) . "\n";

foreach ($message->getResults() as $result) {
    $name  = $result->getTestName();
    $value = $result->getValue();
    if ($value === '') {
        continue; // skip qualitative / image results
    }

    $flag  = $result->getAbnormalFlag();
    $icon  = match ($flag) {
        Result::FLAG_ABOVE_NORMAL, Result::FLAG_CRITICAL_H => '▲',
        Result::FLAG_BELOW_NORMAL, Result::FLAG_CRITICAL_L => '▼',
        Result::FLAG_ABNORMAL, Result::FLAG_WARNING        => '!',
        default                                            => ' ',
    };

    printf("%-14s  %8s  %-12s  %s %s\n",
        $name, $value, $result->getUnits(), $icon, $flag);
}

echo "\n";
$abnormal = array_filter($message->getResults(), fn($r) => $r->isAbnormal() && $r->getValue() !== '');
if (!empty($abnormal)) {
    echo count($abnormal) . " abnormal result(s) detected.\n";
} else {
    echo "All results within reference range.\n";
}
