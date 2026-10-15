<?php
/** Generate offline public-address inputs without storing real server addresses. */
function enomPublicIpv4(int $index): string
{
    return inet_ntop(pack('C4', 11, 0, 0, $index));
}

function enomPublicIpv6(int $index, bool $expanded = false): string
{
    return $expanded ? implode(':', ['2002', 'a', '0', '0', '0', '0', '0', dechex($index)])
        : implode(':', ['2002', 'a', '', dechex($index)]);
}
