<?php

declare(strict_types=1);

namespace Astm\Protocol;

/**
 * ASCII control characters used in the ASTM Low-Level Protocol (LLP).
 *
 * Reference: ASTM E1381-95 §6 (Physical / Data-Link layer used by E1394).
 */
final class Ascii
{
    public const SOH = "\x01"; // Start of Heading        (unused in most instruments)
    public const STX = "\x02"; // Start of Text           (begins a data frame)
    public const ETX = "\x03"; // End of Text             (last frame in a block)
    public const EOT = "\x04"; // End of Transmission     (terminates a session)
    public const ENQ = "\x05"; // Enquiry                 (sender requests the line)
    public const ACK = "\x06"; // Acknowledge             (receiver grants the line)
    public const NAK = "\x15"; // Negative Acknowledge    (receiver busy / error)
    public const ETB = "\x17"; // End of Transmission Block (intermediate frame)
    public const CR  = "\x0D"; // Carriage Return         (record terminator on wire)
    public const LF  = "\x0A"; // Line Feed

    private function __construct() {}
}


