# Contributing

Thank you for considering a contribution to **orderlyconnect/astm-e1394-91**!

---

## Getting started

```bash
git clone https://github.com/OrderlyConnect/astm-e1394-91.git
cd astm-e1394-91
composer install
```

## Running the test suite

```bash
composer test
```

All 167 tests must pass before a pull request can be merged.

## Coding standards

- PHP 8.1+ features only (no deprecated syntax).
- Strict types (`declare(strict_types=1)`) in every file.
- PSR-4 autoloading — one class per file, filename matches class name.
- PSR-12 code style.
- Every new public method must have a PHPDoc block.
- Every new feature must be accompanied by tests.

## Pull request guidelines

1. Fork the repository and create a feature branch from `main`.
2. Write tests first (or alongside) your changes.
3. Run `composer test` and ensure all tests pass.
4. Keep commits atomic and write clear commit messages.
5. Open a PR against `main` with a description of what changed and why.

## Reporting bugs

Please open a [GitHub Issue](https://github.com/OrderlyConnect/astm-e1394-91/issues) and include:

- PHP version (`php --version`)
- A minimal reproducing code snippet
- The raw ASTM message that caused the problem (anonymise patient data)

## Adding a new transport

Implement `Astm\Transport\TransportInterface` (6 methods), place the class in
`src/Transport/`, and add tests in `tests/`.  See `src/Transport/TcpTransport.php`
for a reference implementation.

## Adding support for a new record type

Extend `Astm\Records\AbstractRecord`, register it with
`Astm\Parser::registerRecordType()`, and add typed accessors.
See `src/Records/Result.php` for a reference implementation.

---

## Licence

By contributing you agree that your changes will be released under the
[MIT licence](LICENSE).
