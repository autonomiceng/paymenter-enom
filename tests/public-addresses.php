<?php
/**
 * Generate synthetic public-address inputs for offline glue validation.
 * Documentation ranges are rejected by the product, so successful cases need other addresses.
 * Injected transports record these values without contacting them.
 */
function enomPublicIpv4(int $index): string
{
    return inet_ntop(pack('C4', 11, 0, 0, $index));
}

function enomPublicIpv6(int $index, bool $expanded = false): string
{
    return $expanded ? implode(':', ['2002', 'a', '0', '0', '0', '0', '0', dechex($index)])
        : implode(':', ['2002', 'a', '', dechex($index)]);
}
