#!/usr/bin/env php
<?php

/**
 * Example 6 — Receive ASTM messages from an RS-232 instrument.
 *
 * Replace /dev/ttyUSB0 and the baud rate with your instrument's settings.
 * Common instrument baud rates: 9600 (older), 19200, 38400, 115200 (newer).
 *
 * Usage:
 *   php examples/06_serial_instrument.php /dev/ttyUSB0 9600
 *
 * Prerequisites:
 *   - PHP must be able to open the device (add user to 'dialout' group on Linux)
 *   - stty must be available
 *
 * This example is intentionally NOT run in the test suite because it requires
 * physical hardware.  It is provided as a reference implementation.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Astm\Astm;
use Astm\Message;
use Astm\Receiver;
use Astm\Transport\SerialTransport;

$device = $argv[1] ?? '/dev/ttyUSB0';
$baud   = (int) ($argv[2] ?? 9600);

echo "ASTM Serial Receiver\n";
echo "════════════════════\n";
echo "Device : {$device}\n";
echo "Baud   : {$baud}\n\n";

// ── Transport ──────────────────────────────────────────────────────────────
$transport = new SerialTransport(
    device:      $device,
    baud:        $baud,
    dataBits:    8,
    parity:      'none',
    stopBits:    1,
    readTimeout: 60,
);

// ── Receiver ───────────────────────────────────────────────────────────────
$receiver = new Receiver(
    transport:       $transport,
    onMessage:       function (Message $msg): void {
        echo "\n[" . date('H:i:s') . "] Message received\n";
        echo "  Instrument : " . $msg->getHeader()->getSenderName() . "\n";
        echo "  Results    : " . count($msg->getResults()) . "\n";

        foreach ($msg->getResultMap() as $test => $data) {
            $flag = $data['flag'] ?: ' ';
            printf("  %-12s %8s %-10s [%s]\n",
                $test, $data['value'], $data['units'], $flag);
        }
    },
    verifyChecksums: true,
);

// ── Connect and listen ─────────────────────────────────────────────────────
echo "Opening {$device} … ";
try {
    $transport->connect();
    echo "OK\n";
    echo "Waiting for ENQ from instrument (press Ctrl-C to stop)…\n\n";
    $receiver->listen(); // blocks forever
} catch (\Astm\Exceptions\ConnectionException $e) {
    echo "FAILED\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "\nTip: make sure the device exists and you have read/write permission.\n";
    echo "     On Linux: sudo usermod -aG dialout \$USER\n";
    exit(1);
}
