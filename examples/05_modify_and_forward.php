#!/usr/bin/env php
<?php

/**
 * Example 5 — Clone a received message, enrich it with LIS data, and
 *             re-send or save it.
 *
 * Demonstrates:
 *  - MessageBuilder::fromMessage() to clone a parsed message
 *  - Astm::modify() facade shortcut
 *  - Adding a comment, then re-serialising
 *  - Writing the enriched message to a file
 *
 * Usage:
 *   php examples/05_modify_and_forward.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Astm\Astm;
use Astm\MessageBuilder;

// ── Simulate receiving a message from an instrument ───────────────────────
$raw = <<<'ASTM'
H|\^&|||XN-350^00-14^13126||||||||E1394-97
P|1||||^^|||F
O|1||S-99001^M|^^^^WBC\^^^^HGB|||||||N||||||||||||||F
R|1|^^^^WBC^1|14.8|10*3/uL||H||F||||20250401090000
R|2|^^^^HGB^1|8.2|g/dL||L||F||||20250401090000
R|3|^^^^PLT^1|89|10*3/uL||L||F||||20250401090000
L|1|N
ASTM;

$received = Astm::parse($raw);

echo "Received message:\n";
foreach ($received->getRecords() as $r) {
    echo '  ' . substr($r->toString(), 0, 70) . "\n";
}
echo "\n";

// ── Clone and enrich ──────────────────────────────────────────────────────
$enriched = Astm::modify($received)          // same as MessageBuilder::fromMessage($received)
    ->comment('Reviewed by LIS — critical values telephoned to ward at 09:15', 'LIS', 'I')
    ->comment('Repeat specimen requested', 'LIS', 'I')
    ->build();

echo "Enriched message:\n";
foreach ($enriched->getRecords() as $r) {
    echo '  ' . $r->getType() . '  ' . substr($r->toString(), 0, 70) . "\n";
}
echo "\n";

// ── Verify result fidelity ────────────────────────────────────────────────
$origMap = $received->getResultMap();
$enrichMap = $enriched->getResultMap();

echo "Result fidelity check:\n";
$ok = true;
foreach ($origMap as $test => $orig) {
    $enr = $enrichMap[$test] ?? null;
    $match = $enr && $enr['value'] === $orig['value'] && $enr['flag'] === $orig['flag'];
    printf("  %-8s  %s\n", $test, $match ? '✓ OK' : '✗ MISMATCH');
    if (!$match) {
        $ok = false;
    }
}
echo "\n";

// ── Serialise to JSON ─────────────────────────────────────────────────────
$json = $enriched->toJson();
echo "JSON export (first 400 chars):\n";
echo substr($json, 0, 400) . "…\n\n";

// ── Write as LLP-framed file ──────────────────────────────────────────────
$outPath = sys_get_temp_dir() . '/enriched_' . uniqid() . '.astm';
Astm::writeFile($enriched, $outPath);
echo "Written to: {$outPath} (" . filesize($outPath) . " bytes)\n";

// ── Round-trip verify ─────────────────────────────────────────────────────
$readBack   = Astm::readFile($outPath)->first();
$comments   = $readBack->getComments();
echo "Round-trip comments recovered: " . count($comments) . "\n";
foreach ($comments as $c) {
    echo "  • " . $c->getCommentText() . "\n";
}

unlink($outPath);
echo "\nDone.\n";
