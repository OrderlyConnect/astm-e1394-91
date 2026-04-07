<?php

declare(strict_types=1);

namespace Astm;

/**
 * Helpers for converting between ASTM timestamp strings and PHP DateTimeImmutable.
 *
 * ASTM E1394-97 uses the following timestamp formats:
 *
 *   YYYYMMDDHHMMSS   – date + time (14 chars)  — most result/order fields
 *   YYYYMMDD         – date only   (8 chars)   — birthdate
 *   YYYYMMDDHHMM     – date + HH:MM (12 chars) — some instruments omit seconds
 *
 * All parsing is deliberately lenient: leading/trailing whitespace is trimmed
 * and the minimum required precision is 8 chars (date only).
 */
final class DateTimeHelper
{
    private function __construct() {}

    // -----------------------------------------------------------------------
    //  Parsing
    // -----------------------------------------------------------------------

    /**
     * Parse an ASTM timestamp string into a DateTimeImmutable.
     *
     * Accepts:
     *  - YYYYMMDDHHMMSS  (14 chars)
     *  - YYYYMMDDHHMM    (12 chars)
     *  - YYYYMMDD        (8 chars)
     *
     * @param string|null $raw  Raw field value from an ASTM record.
     * @return DateTimeImmutable|null  Null when the string is empty or unparseable.
     */
    public static function parse(?string $raw): ?\DateTimeImmutable
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $formats = [
            14 => 'YmdHis',
            12 => 'YmdHi',
            8  => 'Ymd',
        ];

        $len = strlen($raw);
        $fmt = $formats[$len] ?? null;

        if ($fmt === null) {
            // Try the longest format for non-standard lengths
            $fmt = strlen($raw) > 8 ? 'YmdHis' : 'Ymd';
            $raw = str_pad($raw, strlen($raw) >= 14 ? 14 : 8, '0');
        }

        $dt = \DateTimeImmutable::createFromFormat($fmt, $raw);
        return $dt !== false ? $dt : null;
    }

    /**
     * Parse a date-only string (YYYYMMDD) into a DateTimeImmutable.
     */
    public static function parseDate(?string $raw): ?\DateTimeImmutable
    {
        $raw = trim((string) $raw);
        if (strlen($raw) < 8) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Ymd', substr($raw, 0, 8));
        return $dt !== false ? $dt : null;
    }

    // -----------------------------------------------------------------------
    //  Formatting
    // -----------------------------------------------------------------------

    /**
     * Format a DateTimeImmutable as an ASTM date-time string (YYYYMMDDHHMMSS).
     */
    public static function format(\DateTimeImmutable $dt): string
    {
        return $dt->format('YmdHis');
    }

    /**
     * Format a DateTimeImmutable as an ASTM date-only string (YYYYMMDD).
     */
    public static function formatDate(\DateTimeImmutable $dt): string
    {
        return $dt->format('Ymd');
    }

    /**
     * Return the current UTC time formatted as an ASTM timestamp.
     */
    public static function now(): string
    {
        return self::format(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /**
     * Return today's date formatted as an ASTM date string.
     */
    public static function today(): string
    {
        return self::formatDate(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    // -----------------------------------------------------------------------
    //  Validation
    // -----------------------------------------------------------------------

    /**
     * Return true when $raw looks like a valid ASTM date or datetime string.
     */
    public static function isValid(?string $raw): bool
    {
        return self::parse($raw) !== null;
    }
}
