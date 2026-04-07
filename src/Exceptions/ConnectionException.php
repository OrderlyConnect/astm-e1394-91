<?php

declare(strict_types=1);

namespace Astm\Exceptions;

/**
 * Thrown when a transport-level operation fails — connection refused,
 * timeout, write error, etc.
 *
 * Distinct from {@see ParseException} (malformed ASTM data) so callers can
 * catch transport and protocol errors independently.
 */
class ConnectionException extends AstmException {}
