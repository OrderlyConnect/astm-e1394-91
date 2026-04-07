#!/usr/bin/env php
<?php

/**
 * Example 3 — TCP server that receives ASTM messages from instruments.
 *
 * Start this script, then point an instrument (or the send example) at it:
 *
 *   php examples/03_tcp_server.php 0.0.0.0 3001
 *
 * Send a test message from another terminal:
 *
 *   php examples/02_build_and_send.php 127.0.0.1 3001
 *
 * Press Ctrl-C to stop.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Astm\Astm;
use Astm\Message;
use Astm\TcpServer;

$host = $argv[1] ?? '0.0.0.0';
$port = (int) ($argv[2] ?? 3001);

echo "ASTM TCP Server\n";
echo "═══════════════\n";
echo "Listening on {$host}:{$port} — press Ctrl-C to stop.\n\n";

$server = new TcpServer(
    host:            $host,
    port:            $port,
    backlog:         10,
    readTimeoutSec:  60,
    verifyChecksums: true,
);

// ── Graceful shutdown ──────────────────────────────────────────────────────
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGINT,  fn () => $server->stop());
    pcntl_signal(SIGTERM, fn () => $server->stop());
}

// ── Message handler ────────────────────────────────────────────────────────
$server->serve(function (Message $msg, string $remoteAddr): void {
    $ts      = date('Y-m-d H:i:s');
    $header  = $msg->getHeader();
    $patient = $msg->getFirstPatient();
    $results = $msg->getResults();

    echo "┌── [{$ts}] from {$remoteAddr}\n";
    echo "│   Instrument : " . ($header?->getSenderName() ?? '?') . "\n";

    if ($patient) {
        $name = trim($patient->getLastName() . ' ' . $patient->getFirstName());
        echo "│   Patient    : {$name} ({$patient->getSex()})\n";
    }

    echo "│   Results    : " . count($results) . "\n";

    // Print abnormal results
    $abnormal = array_filter($results, fn ($r) => $r->isAbnormal() && $r->getValue() !== '');
    if (!empty($abnormal)) {
        echo "│   ⚠ Abnormal :\n";
        foreach ($abnormal as $r) {
            printf("│     %-10s  %6s %-10s  [%s]\n",
                $r->getTestName(), $r->getValue(),
                $r->getUnits(), $r->getAbnormalFlag());
        }
    }

    // Dump to JSON file for downstream processing
    $outDir = sys_get_temp_dir() . '/astm_received';
    @mkdir($outDir, 0755, true);
    $file = $outDir . '/' . date('YmdHis') . '_' . uniqid() . '.json';
    file_put_contents($file, $msg->toJson());
    echo "│   Saved      : {$file}\n";
    echo "└" . str_repeat('─', 50) . "\n\n";
});
