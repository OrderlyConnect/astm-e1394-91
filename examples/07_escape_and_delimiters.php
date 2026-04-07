#!/usr/bin/env php
<?php

/**
 * Example 7 — EscapeCodec and custom delimiters.
 *
 * Demonstrates:
 *  - Encoding strings that contain ASTM delimiter characters before embedding
 *    them in a result value.
 *  - Using custom delimiters throughout the builder and parser.
 *
 * Usage:
 *   php examples/07_escape_and_delimiters.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Astm\Astm;
use Astm\Delimiters;
use Astm\EscapeCodec;

// ── EscapeCodec — protect delimiter characters in free-text values ─────────
echo "EscapeCodec demo\n";
echo "════════════════\n\n";

$codec = Astm::escapeCodec();

$notes = [
    'Normal | expected range 4.0-11.0',
    'Result: 5.0 ^ sub-component note',
    'Batch\Lot XY-20250401',
    'Reference & quality: see lab manual',
    'Multi: A|B^C\\D&E',
];

printf("%-40s  %s\n", 'Original', 'Encoded');
echo str_repeat('─', 80) . "\n";
foreach ($notes as $note) {
    $encoded = $codec->encode($note);
    $decoded = $codec->decode($encoded);
    printf("%-40s  %s\n", $note, $encoded);
    assert($decoded === $note, "Round-trip failed for: {$note}");
}
echo "\nAll round-trips verified ✓\n\n";

// ── Custom delimiters ──────────────────────────────────────────────────────
echo "Custom delimiter demo\n";
echo "═════════════════════\n\n";

// Some older instruments use non-standard delimiters
$customDelimiters = new Delimiters(
    field:     '!',
    component: '@',
    repeat:    '#',
    escape:    '$',
);

echo "Field     : '{$customDelimiters->field}'\n";
echo "Component : '{$customDelimiters->component}'\n";
echo "Repeat    : '{$customDelimiters->repeat}'\n";
echo "Escape    : '{$customDelimiters->escape}'\n\n";

$msg = Astm::build($customDelimiters)
    ->sender('CUSTOM-INST', 'v2.0')
    ->patient(fn ($p) => $p->id('P-001')->name('Smith', 'Jane')->sex('F'))
    ->result(fn ($r) => $r->test('WBC')->value('7.2')->units('10*3/uL')->flag('N'))
    ->result(fn ($r) => $r->test('HGB')->value('13.5')->units('g/dL')->flag('N'))
    ->build();

echo "Serialised with custom delimiters:\n";
foreach ($msg->getRecords() as $r) {
    echo '  ' . $r->toString() . "\n";
}
echo "\n";

// Verify the field delimiter is '!' not '|'
$hLine = $msg->getHeader()->toString();
assert($hLine[1] === '!', "Expected '!' as field delimiter");
assert(!str_contains($hLine, '|'), "Standard '|' should not appear");
echo "Custom field delimiter '!' confirmed ✓\n\n";

// Parse the custom-delimiter message back
$reparsed = Astm::parse($msg->toString("\n"));
$map      = $reparsed->getResultMap();

echo "Re-parsed results:\n";
foreach ($map as $test => $data) {
    printf("  %-8s  %6s %-10s  [%s]\n",
        $test, $data['value'], $data['units'], $data['flag']);
}

assert(isset($map['WBC']), 'WBC must be present after re-parse');
assert($map['WBC']['value'] === '7.2', 'WBC value must survive re-parse');
echo "\nRe-parse round-trip verified ✓\n";
