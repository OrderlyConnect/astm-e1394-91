# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 1.x     | ✅ Yes     |

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Instead, e-mail **solutionsorderly@gmail.com** with:

- A description of the vulnerability and its potential impact.
- Steps to reproduce (or a proof-of-concept).
- Affected versions.

We will acknowledge your report within 48 hours and aim to release a patch
within 14 days for confirmed vulnerabilities.

## Scope

Issues in scope:

- Checksum bypass in the LLP protocol layer.
- Parser crashes or memory exhaustion on malformed input.
- Information disclosure from parsed message fields.
- Denial-of-service via crafted ASTM messages.

Issues out of scope:

- Vulnerabilities in your application code that uses this library.
- Network-level attacks (use TLS / VPN for transport security).
