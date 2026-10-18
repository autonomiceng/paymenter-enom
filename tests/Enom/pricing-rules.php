<?php
require_once dirname(__DIR__).'/paymenter-test-app.php';
require_once __DIR__.'/../../Enom/Services/PricingRule.php';

use Paymenter\Extensions\Servers\Enom\Services\PricingRule;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
foreach ([['15.00', 1500], ['-3.25', -325], ['-0.01', -1], [15.25, 1525], [0, 0], ['9999999999.99', 999999999999]] as [$value, $minor]) {
    check(PricingRule::minor($value) === $minor && PricingRule::decimal($minor) === number_format((float) $value, 2, '.', ''), 'Native minor-unit round trip differs.');
}
check(PricingRule::minor('20.00') + PricingRule::minor('-5.00') === 1500, 'Signed net payment lost precision.');
foreach ([null, true, [], '1e2', ' 1.00', '1.001', 1.001, INF, NAN] as $bad) {
    try { PricingRule::minor($bad); } catch (RuntimeException) { $checks++; continue; }
    throw new RuntimeException('Invalid native money accepted.');
}
$rule = PricingRule::DEFAULT;
foreach ([
    ['1.0049', '0', 'none', '1.00'], ['1.0050', '0', 'none', '1.01'], ['1.0051', '0', 'none', '1.01'],
    ['0.9999', '0', 'whole', '1.00'], ['1.4999', '0', 'half', '1.50'], ['0.9899', '0', 'ending_99', '0.99'],
    ['15.5500', '0', 'none', '15.55'], ['8.9500', '30', 'none', '11.64'],
    ['0.0100', '49.99', 'none', '0.01'], ['0.0100', '50', 'none', '0.02'],
    ['10.0000', '0', 'whole', '10.00'], ['10.0001', '0', 'whole', '11.00'],
    ['10.5000', '0', 'half', '10.50'], ['10.5001', '0', 'half', '11.00'],
    ['10.0001', '0', 'half', '10.50'], ['10.9900', '0', 'ending_99', '10.99'],
    ['10.9901', '0', 'ending_99', '11.99'], ['11.0000', '0', 'ending_99', '11.99'],
    ['0.0000', '0', 'whole', '0.00'], ['0.0000', '0', 'half', '0.00'], ['0.0000', '0', 'ending_99', '0.99'],
    ['1.0000', '0.01', 'whole', '2.00'], ['1.5000', '0.01', 'half', '2.00'], ['1.9900', '0.01', 'ending_99', '2.99'],
    ['0.0001', '0.01', 'whole', '1.00'], ['0.0001', '0.01', 'half', '0.50'],
] as [$cost, $markup, $rounding, $expected]) {
    $actual = PricingRule::price($cost, array_replace($rule, ['markup' => $markup, 'round_up' => $rounding]), 'register');
    check($actual === $expected, "$cost + $markup%, $rounding: $actual instead of $expected");
}
check(PricingRule::price('8.9500', array_replace($rule, ['mode' => 'markup_amount', 'markup' => '1.05']), 'register') === '10.00', 'Amount markup differs.');
check(PricingRule::price('99999999.9999', array_replace($rule, ['markup' => '1000']), 'register') === '1100000000.00', 'Large exact price overflowed.');
check(PricingRule::total('11.64', 3) === '34.92', 'Rounding did not precede term multiplication.');
check(PricingRule::total('8.95', 2) === '17.90', 'Exact two-year .net cost differs.');
check(!PricingRule::covers('8.95', '8.9501') && PricingRule::covers('8.96', '8.9501'), 'Wholesale margin lost four-place precision.');
foreach (['-1', '1e3', '1.23456', '', ' 1.00', 'NaN'] as $bad) { check(PricingRule::wholesale($bad) === null, 'Bad wholesale accepted.'); }
$fixed = array_replace($rule, ['mode' => 'fixed', 'fixed' => ['register' => '12.00', 'renew' => '13.00', 'transfer' => '14.00']]);
check(PricingRule::price('8.95', $fixed, 'register') === '12.00' && PricingRule::price('8.95', $fixed, 'renew') === '13.00', 'Action-specific fixed prices differ.');
foreach ([array_replace($rule, ['mode' => 'bad']), array_replace($rule, ['markup' => '-1']), array_replace($rule, ['markup' => '100001']),
    array_replace($rule, ['round_up' => 'bad']), array_replace($fixed, ['fixed' => ['register' => '0.00']])] as $bad) {
    try { PricingRule::validate($bad); throw new RuntimeException('Invalid rule accepted.'); }
    catch (InvalidArgumentException) { $checks++; }
}
try { PricingRule::price('99999999.9999', array_replace($rule, ['markup' => '100000']), 'register'); throw new RuntimeException('Price overflow accepted.'); }
catch (InvalidArgumentException) { $checks++; }

echo 'Pricing rules: '.$checks.' checks passed (offline).'.PHP_EOL;
