# Changelog

All notable changes to this project will be documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

- Laravel service provider (`AstmServiceProvider`)
- Symfony bundle (`AstmBundle`)
- ASTM E1381-02 (updated LLP spec) support
- PHPStan level-8 type coverage

---

## [1.0.0] — 2025-04-06

### Added

#### Core parsing
- `Parser` — parses raw ASTM E1394-97 strings into `Message` objects
- `Delimiters` — value object auto-detected from the H record encoding field
- Seven typed record classes: `Header`, `Patient`, `Order`, `Result`, `Comment`, `Query`, `Terminator`
- `AbstractRecord` — base class with 1-based field access, component/repeat splitting, `toArray()`
- `Parser::registerRecordType()` — extensibility hook for vendor-specific record types
- Strict and non-strict parser modes

#### Message building
- `MessageBuilder` — fluent API with auto-incrementing sequence numbers
- `MessageBuilder::fromMessage()` — clone and modify an existing message
- `MessageBuilder::header()` — fine-grained H-record configuration via `HeaderBuilder`
- `MessageBuilder::sender()` — shorthand H-record configuration
- `HeaderBuilder` — typed sub-builder for all 14 H-record fields
- Sub-builders: `PatientBuilder`, `OrderBuilder`, `ResultBuilder`, `QueryBuilder`
- `ResultBuilder::referenceRangeFromBounds()` — typed "low-high" range helper
- `ResultBuilder::referenceRangeLimit()` — typed operator-limit range helper
- Support for custom `Delimiters` throughout the builder

#### LLP wire protocol (E1381-95)
- `Frame` — immutable value object with ASTM checksum calculation and verification
- `LlpEncoder` — ETX per record, ETB for long-record splits, frame numbers cycle 1-7
- `LlpDecoder` — streaming state machine; handles incremental delivery, ETB reassembly, EOT boundaries
- `Ascii` — constants for STX, ETX, ETB, ENQ, ACK, NAK, EOT, CR, LF

#### Session layer
- `Sender` — full ENQ→ACK→frames→EOT handshake; configurable retries and timeouts
- `Receiver` — byte-level state machine; blocking (`listen()`) and non-blocking (`tick()`) modes
- `TcpServer` — blocking accept loop with `StreamTransport` per connection; graceful `stop()`

#### Transports
- `TcpTransport` — TCP socket with configurable connect/read timeouts; throws `ConnectionException`
- `SerialTransport` — RS-232 via `/dev/tty*` (Linux/macOS) or `COM*` (Windows); `stty`/`mode.com` port configuration
- `FileTransport` — read/write/append LLP-framed binary files; named constructors
- `MemoryTransport` — in-memory loopback for unit tests
- `StreamTransport` — wraps any open PHP stream resource
- `TransportInterface` — six-method contract for custom transports

#### Utility
- `EscapeCodec` — encode/decode `&F&`, `&R&`, `&S&`, `&E&` per E1394-97 §6.6
- `MessageValidator` — structural validation with correct group-scoped sequence numbering (R/C reset per O group, C resets per non-C parent — matching real instrument behaviour)
- `MessageCollection` — ordered batch: `getAbnormalResults()`, `getHighResults()`, `getLowResults()`, `getResultsByTest()`, `getAllResultsMapped()`, `getMessagesWithAbnormalities()`, `toFile()`, `fromFile()`, `fromString()`, immutable `add()`
- `MessageDiff` — field-by-field comparison: `getFieldChanges()`, `getChangedResults()`, `getSummary()`
- `DateTimeHelper` — centralised ASTM timestamp parsing/formatting; wired into all record `*Object()` methods
- `Message::toArray()` / `Message::toJson()` — serialisation
- `Message::getAbnormalResults()` / `getFinalResults()` / `hasAbnormalities()` — convenience accessors
- `ConnectionException` — distinct from `ParseException` for transport-level failures

#### Facade
- `Astm` — single static entry point:
  `parse()`, `parseFile()`, `decodeLlp()`, `build()`, `modify()`, `send()`, `sendTcp()`, `writeFile()`, `readFile()`, `listen()`, `validate()`, `diff()`, `escapeCodec()`, `parseDateTime()`, `formatDateTime()`, `now()`, `version()`

#### CLI tool
- `bin/astm` — command-line inspector: `parse`, `decode`, `verify`, `json`, `summary`
- Installed globally via `composer global require` or locally via `vendor/bin/astm`

#### Developer experience
- **196 tests · 437 assertions** — all green on PHP 8.1, 8.2, 8.3
- `phpunit.xml` — test configuration
- `phpstan.neon` — static analysis configuration (level 6)
- `.php-cs-fixer.php` — PSR-12 code style configuration
- `.github/workflows/tests.yml` — CI: PHP 8.1/8.2/8.3 matrix
- `.github/workflows/release.yml` — auto GitHub Release on `v*` tags
- `.github/ISSUE_TEMPLATE/` — bug report and feature request templates
- `.github/pull_request_template.md`
- `CONTRIBUTING.md`, `SECURITY.md`, `PACKAGIST.md`
- 7 runnable example scripts in `examples/`

