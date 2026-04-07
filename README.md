# OrderlyConnect ASTM E1394-97

[![Tests](https://github.com/OrderlyConnect/astm-e1394-91/actions/workflows/tests.yml/badge.svg)](https://github.com/OrderlyConnect/astm-e1394-91/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/orderlyconnect/astm-e1394-91.svg)](https://packagist.org/packages/orderlyconnect/astm-e1394-91)
[![PHP Version](https://img.shields.io/packagist/php-v/orderlyconnect/astm-e1394-91.svg)](https://packagist.org/packages/orderlyconnect/astm-e1394-91)
[![License](https://img.shields.io/github/license/OrderlyConnect/astm-e1394-91.svg)](LICENSE)

A complete, production-ready PHP 8.1+ library for **parsing**, **building**, and **transmitting** ASTM E1394-97 messages — the standard used by clinical laboratory instruments (haematology analysers, chemistry analysers, coagulation systems, etc.) to exchange patient results with LIS/HIS systems.

Validated against real Sysmex XN-350 haematology analyser output.

---

## Features

- **Parse** plain-text and LLP-framed ASTM messages into typed PHP objects
- **Build** messages with a fluent, type-safe builder API
- **Send** over TCP, RS-232 serial, or files with full ENQ/ACK/EOT handshake
- **Receive** inbound instrument connections with a byte-level state machine receiver
- **TCP Server** — accept multiple instrument connections in a dedicated process
- **LLP framing** — ASTM E1381-95 checksum, ETX/ETB frame splitting, streaming decoder
- **EscapeCodec** — encode/decode `&F& &R& &S& &E&` sequences (§6.6)
- **MessageValidator** — structural rule checking
- **MessageCollection** — batch processing with cross-session queries
- **MessageDiff** — field-by-field comparison for correction workflows
- **JSON/array serialisation** — `toArray()` / `toJson()`
- Zero runtime dependencies

---

## Installation

```bash
composer require orderlyconnect/astm-e1394-91
```

**Requirements:** PHP 8.1+.  
Serial port support requires `stty` (Linux/macOS) or `mode.com` (Windows).

---

## Quick Start

```php
use Astm\Astm;

// ── Parse ──────────────────────────────────────────────────────────────────
$message = Astm::parse(file_get_contents('/tmp/result.astm'));

foreach ($message->getResultMap() as $test => $data) {
    printf("%-10s  %6s %-10s  flag=%s\n",
        $test, $data['value'], $data['units'], $data['flag']);
}

// ── Build ──────────────────────────────────────────────────────────────────
$message = Astm::build()
    ->sender('MY-LIS', 'v2.0')
    ->patient(fn ($p) => $p->id('PAT-001')->name('Okafor', 'Ngozi')->sex('F'))
    ->order(fn ($o) => $o->specimenId('EDTA-001')->addTest('WBC')->addTest('HGB'))
    ->result(fn ($r) => $r
        ->test('WBC')->value('7.2')->units('10*3/uL')
        ->referenceRangeFromBounds(4.0, 11.0)->flag('N'))
    ->result(fn ($r) => $r
        ->test('HGB')->value('14.1')->units('g/dL')->flag('N'))
    ->build();

// ── Send over TCP ──────────────────────────────────────────────────────────
Astm::sendTcp($message, '192.168.1.50', port: 3001);

// ── Receive (blocking server) ──────────────────────────────────────────────
Astm::listen(function (\Astm\Message $msg, string $from): void {
    echo "[{$from}] " . count($msg->getResults()) . " results\n";
    file_put_contents('/var/log/astm/' . uniqid() . '.json', $msg->toJson());
}, host: '0.0.0.0', port: 3001);
```

---

## Table of Contents

- [Parsing](#parsing)
- [Building Messages](#building-messages)
- [Cloning & Modifying](#cloning--modifying)
- [Sending](#sending)
- [Receiving](#receiving)
- [TCP Server](#tcp-server)
- [Transports](#transports)
- [LLP Protocol](#llp-protocol)
- [Utility Classes](#utility-classes)
- [Serialisation](#serialisation)
- [ASTM Record Reference](#astm-record-reference)
- [Examples](#examples)
- [Testing](#testing)
- [Extending](#extending)
- [Publishing to Packagist](#publishing-to-packagist)

---

## Parsing

```php
// Single message from a string
$message = Astm::parse($rawString);

// All sessions from a plain-text file → MessageCollection
$collection = Astm::parseFile('/path/to/session.astm');

// Decode an LLP-framed binary blob
$collection = Astm::decodeLlp(file_get_contents('/captures/session.bin'));

// Message accessors
$message->getHeader();            // ?Header
$message->getFirstPatient();      // ?Patient
$message->getFirstOrder();        // ?Order
$message->getResults();           // Result[]
$message->getAbnormalResults();   // Result[]  — flag ≠ '' and ≠ 'N'
$message->getFinalResults();      // Result[]  — status = 'F'
$message->hasAbnormalities();     // bool
$message->getComments();          // Comment[]
$message->getTerminator();        // ?Terminator
$message->getRecordsByType('R');  // AbstractRecord[]

// Result map — keyed by test name
// ['WBC' => ['value'=>'7.2','units'=>'10*3/uL','flag'=>'N','status'=>'F'], ...]
$message->getResultMap();
```

---

## Building Messages

```php
use Astm\Astm;
use Astm\Records\Result;

$message = Astm::build()
    ->sender('MY-LIS', 'v2.0', processingId: 'P', receiverId: 'LAB-SYSTEM')
    ->patient(fn ($p) => $p
        ->id('PAT-001', 'LAB-001')
        ->name('Smith', 'John', 'A')      // last, first, middle
        ->sex('M')                         // M / F / U
        ->birthdate('19850310')            // YYYYMMDD
        ->address('123 Main St')
        ->phone('555-1234')
        ->physician('DR-JONES'))
    ->order(fn ($o) => $o
        ->specimenId('EDTA-001')
        ->addTest('WBC')->addTest('HGB')->addTest('PLT')
        ->collectionDateTime('20250329143000')
        ->priority('R')                    // R=routine, S=stat
        ->reportType('F'))
    ->result(fn ($r) => $r
        ->test('WBC')
        ->value('7.2')
        ->units('10*3/uL')
        ->referenceRangeFromBounds(4.0, 11.0)   // produces "4-11"
        ->flag(Result::FLAG_NORMAL)
        ->status(Result::STATUS_FINAL)
        ->completedAt('20250329143330'))
    ->result(fn ($r) => $r
        ->test('PSA')
        ->value('2.1')
        ->units('ng/mL')
        ->referenceRangeLimit('<', 4.0)          // produces "<4"
        ->flag('N'))
    ->comment('Sample slightly haemolysed')
    ->build();
```

### Custom delimiters

```php
use Astm\Delimiters;

$d = new Delimiters(field: '!', component: '@', repeat: '#', escape: '$');
$message = Astm::build($d)->sender('MY-LIS')->result(...)->build();
```

---

## Cloning & Modifying

```php
// Clone a received message and enrich it
$enriched = Astm::modify($received)
    ->comment('Critical values phoned to ward at 09:15', 'LIS', 'I')
    ->build();

// Sequence numbers continue correctly from the original
// Compare the two messages
$diff = Astm::diff($received, $enriched);
if ($diff->hasDifferences()) {
    foreach ($diff->getSummary() as $line) {
        echo $line . "\n";
    }
}
```

---

## Sending

```php
// TCP — one-liner
Astm::sendTcp($message, '192.168.1.50', port: 3001);

// TCP — fine-grained
use Astm\Sender;
use Astm\Transport\TcpTransport;

$transport = new TcpTransport('192.168.1.50', 3001, connectTimeout: 10, readTimeout: 30);
$sender    = new Sender($transport, maxEnqRetries: 3, maxFrameRetries: 3);
$transport->connect();
try {
    $sender->send($message);
} finally {
    $transport->disconnect();
}

// Serial port
use Astm\Transport\SerialTransport;
$transport = new SerialTransport('/dev/ttyUSB0', baud: 9600);
(new \Astm\Sender($transport))->send($message);

// Write LLP-framed binary file
Astm::writeFile($message, '/tmp/output.astm');
$collection = Astm::readFile('/tmp/output.astm');
```

---

## Receiving

```php
use Astm\Receiver;
use Astm\Transport\TcpTransport;

$transport = new TcpTransport('0.0.0.0', 3001);
$transport->connect();

$receiver = new Receiver(
    transport: $transport,
    onMessage: function (\Astm\Message $msg): void {
        foreach ($msg->getAbnormalResults() as $r) {
            printf("⚠  %-12s %6s [%s]\n", $r->getTestName(), $r->getValue(), $r->getAbnormalFlag());
        }
    },
);

// Non-blocking (event loop)
while (true) {
    $receiver->tick();
    while ($msg = $receiver->popMessage()) { handleMessage($msg); }
    usleep(1_000);
}

// Blocking
$receiver->listen();
```

---

## TCP Server

```php
use Astm\TcpServer;

$server = new TcpServer(host: '0.0.0.0', port: 3001, verifyChecksums: true);

pcntl_signal(SIGTERM, fn () => $server->stop());

$server->serve(function (\Astm\Message $msg, string $remoteAddr): void {
    $sender  = $msg->getHeader()->getSenderName();
    $patient = $msg->getFirstPatient();

    printf("[%s] %s — %s, %s — %d results\n",
        $remoteAddr, $sender,
        $patient?->getLastName(), $patient?->getFirstName(),
        count($msg->getResults()));

    // Persist
    file_put_contents('/var/log/astm/' . uniqid() . '.json', $msg->toJson());
});
```

---

## Transports

| Class | Use Case |
|---|---|
| `TcpTransport` | Connect to an instrument over TCP/IP |
| `SerialTransport` | RS-232 via `/dev/ttyUSBx` or `COM3` |
| `FileTransport` | Read / write LLP-framed binary files |
| `MemoryTransport` | Unit tests and simulation |
| `StreamTransport` | Wrap any open PHP stream resource |

Implement `TransportInterface` (6 methods) to add your own.

---

## LLP Protocol

```
ENQ                    ← sender requests the line
    ACK                ← receiver grants
STX fn data ETX C1C2 CR LF  ← one record frame
    ACK
EOT                    ← sender ends session
```

- **ETX** — ends each ASTM record's last chunk
- **ETB** — splits records longer than `maxDataBytes` (default 240)
- Frame numbers cycle **1–7**
- **Checksum** = sum(bytes of frameNum + data + ETX/ETB) mod 256, two uppercase hex digits

```php
use Astm\Protocol\{LlpEncoder, LlpDecoder, Frame, Ascii};

$encoder = new LlpEncoder(maxDataBytes: 240);
$frames  = $encoder->encode($message);

$decoder = new LlpDecoder(verifyChecksums: true);
$decoder->feed($chunk);
while ($raw = $decoder->popMessage()) {
    $parsed = (new \Astm\Parser())->parse($raw);
}

$checksum = Frame::checksum('1H|\\^&|||SENDER' . Ascii::ETX);
```

---

## Utility Classes

### EscapeCodec

```php
$codec = Astm::escapeCodec();
$safe  = $codec->encode('Result: 5.0 | note');   // "Result: 5.0 &F& note"
$plain = $codec->decode($safe);
```

| Sequence | Literal |
|---|---|
| `&F&` | `\|` field delimiter |
| `&R&` | `\` repeat delimiter |
| `&S&` | `^` component delimiter |
| `&E&` | `&` escape character |

### MessageValidator

```php
$errors = Astm::validate($message);  // [] = valid
```

### MessageCollection

```php
$collection = Astm::parseFile('/batch/session.astm');

$collection->getAbnormalResults();              // all H/L/A across all sessions
$collection->getHighResults();                  // H/HH only
$collection->getLowResults();                   // L/LL only
$collection->getResultsByTest('WBC');           // cross-session
$collection->getMessagesWithAbnormalities();    // messages with any flag
$collection->getAllResultsMapped();             // grouped by test name
```

### MessageDiff

```php
$diff = Astm::diff($original, $corrected);

$diff->hasDifferences();         // bool
$diff->getFieldChanges();        // [{type, seq, field, old, new}, ...]
$diff->getChangedResults();      // [{test, old, new}, ...]
$diff->getChangedTestNames();    // ['WBC', 'HGB']
$diff->getSummary();             // ['R[1] field 4: "7.2" → "99.9"', ...]
```

### DateTimeHelper

```php
use Astm\DateTimeHelper;

DateTimeHelper::parse('20250329143330');     // DateTimeImmutable
DateTimeHelper::parseDate('19920801');       // DateTimeImmutable
DateTimeHelper::format($dt);                 // '20250329143330'
DateTimeHelper::formatDate($dt);             // '19920801'
DateTimeHelper::now();                       // current UTC as YYYYMMDDHHMMSS
DateTimeHelper::today();                     // current UTC date as YYYYMMDD
DateTimeHelper::isValid('20250329143330');   // true
```

---

## Serialisation

```php
// Wire format
$message->toString("\r");     // ASTM CR-terminated (instrument wire format)
$message->toString("\n");     // newline-separated (logs / files)

// PHP array
$arr = $message->toArray();
// keys: sender, version, datetime, patient, order, results, comments

// JSON
$json = $message->toJson();                           // pretty-printed
$json = $message->toJson(JSON_THROW_ON_ERROR);        // compact

// LLP binary file
Astm::writeFile($message, '/tmp/output.astm');
$collection = Astm::readFile('/tmp/output.astm');
```

---

## ASTM Record Reference

### Result flags

| Constant | Value | Meaning |
|---|---|---|
| `Result::FLAG_NORMAL` | `N` | Within range |
| `Result::FLAG_ABOVE_NORMAL` | `H` | High |
| `Result::FLAG_BELOW_NORMAL` | `L` | Low |
| `Result::FLAG_CRITICAL_H` | `HH` | Critically high |
| `Result::FLAG_CRITICAL_L` | `LL` | Critically low |
| `Result::FLAG_ABNORMAL` | `A` | Qualitative abnormal |
| `Result::FLAG_WARNING` | `W` | Instrument warning |

### Result statuses

| Constant | Value |
|---|---|
| `Result::STATUS_FINAL` | `F` |
| `Result::STATUS_CORRECTION` | `C` |
| `Result::STATUS_PRELIMINARY` | `P` |
| `Result::STATUS_INCOMPLETE` | `I` |

### Exception hierarchy

```
AstmException
├── ParseException
│   └── UnknownRecordTypeException
└── ConnectionException
```

---

## Examples

```bash
php examples/01_parse_file.php                    # parse + display with flags
php examples/02_build_and_send.php [host] [port]  # build, validate, JSON, send
php examples/03_tcp_server.php [host] [port]      # TCP server (Ctrl-C to stop)
php examples/04_batch_collection.php              # batch stats report
php examples/05_modify_and_forward.php            # clone + enrich + file round-trip
php examples/06_serial_instrument.php /dev/ttyUSB0 9600  # RS-232 (hardware)
php examples/07_escape_and_delimiters.php         # EscapeCodec + custom delimiters
```

---

## Testing

```bash
composer install
composer test
```

**167 tests · 371 assertions** across 5 test files covering the full stack.

---

## Extending

### Custom record type

```php
use Astm\Records\AbstractRecord;

final class ManufacturerRecord extends AbstractRecord
{
    public const TYPE = 'M';
    public function getType(): string { return self::TYPE; }
    public function getVendorData(): string { return $this->getField(2); }
}

\Astm\Parser::registerRecordType('M', ManufacturerRecord::class);
$message  = (new \Astm\Parser())->parse($raw);
$mRecords = $message->getRecordsByType('M');
```

### Custom transport

Implement `Astm\Transport\TransportInterface` and pass to `Sender` or `Receiver`.

---

## Publishing to Packagist

See [PACKAGIST.md](PACKAGIST.md) for step-by-step instructions.

---

## Security

Please report vulnerabilities via the process in [SECURITY.md](SECURITY.md).

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

---

## Licence

MIT — see [LICENSE](LICENSE).
