#!/usr/bin/env php
<?php

/**
 * Example 2 — Build a result message and send it to an instrument or LIS over TCP.
 *
 * Usage:
 *   php examples/02_build_and_send.php [host] [port]
 *
 * Without arguments the script only builds and validates the message
 * (no network connection is attempted).
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Astm\Astm;
use Astm\Records\Result;

// ── Build ──────────────────────────────────────────────────────────────────
$message = Astm::build()
    ->sender('MY-LIS', 'v1.0', processingId: 'P')
    ->patient(fn ($p) => $p
        ->id('PAT-20250401-001', 'LAB-20250401-001')
        ->name('Okafor', 'Ngozi', 'A')
        ->sex('F')
        ->birthdate('19920415')
        ->address('12 Victoria Island, Lagos')
        ->phone('+234-800-000-0001')
        ->physician('DR-ADEYEMI'))
    ->order(fn ($o) => $o
        ->specimenId('EDTA-20250401-001')
        ->addTest('WBC')
        ->addTest('RBC')
        ->addTest('HGB')
        ->addTest('HCT')
        ->addTest('PLT')
        ->collectionDateTime('20250401080000')
        ->reportType('F'))
    ->result(fn ($r) => $r->test('WBC') ->value('7.8') ->units('10*3/uL')->referenceRange('4.0-11.0')->flag('N'))
    ->result(fn ($r) => $r->test('RBC') ->value('4.62')->units('10*6/uL')->referenceRange('3.8-5.2')  ->flag('N'))
    ->result(fn ($r) => $r->test('HGB') ->value('13.9')->units('g/dL')   ->referenceRange('12.0-16.0')->flag('N'))
    ->result(fn ($r) => $r->test('HCT') ->value('41.5')->units('%')      ->referenceRange('36.0-46.0')->flag('N'))
    ->result(fn ($r) => $r->test('PLT') ->value('428') ->units('10*3/uL')->referenceRange('150-400')  ->flag(Result::FLAG_ABOVE_NORMAL))
    ->comment('Specimen received in good condition')
    ->build();

// ── Validate ───────────────────────────────────────────────────────────────
$errors = Astm::validate($message);
if (!empty($errors)) {
    echo "Validation errors:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
    exit(1);
}

// ── Inspect ────────────────────────────────────────────────────────────────
echo "Message built successfully:\n";
foreach ($message->getRecords() as $r) {
    echo '  ' . $r->getType() . '  ' . substr($r->toString(), 0, 70) . "\n";
}
echo "\n";

// ── JSON export ────────────────────────────────────────────────────────────
echo "JSON snapshot:\n";
echo $message->toJson() . "\n\n";

// ── Send (optional) ────────────────────────────────────────────────────────
$host = $argv[1] ?? null;
$port = isset($argv[2]) ? (int) $argv[2] : 3001;

if ($host !== null) {
    echo "Sending to {$host}:{$port} …\n";
    try {
        Astm::sendTcp($message, $host, $port, connectTimeout: 10);
        echo "Sent successfully.\n";
    } catch (\Astm\Exceptions\ConnectionException $e) {
        echo "Connection error: " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "(No host supplied — skipping send. Usage: php {$argv[0]} <host> [port])\n";
}
