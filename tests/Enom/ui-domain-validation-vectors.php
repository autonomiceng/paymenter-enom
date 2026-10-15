<?php
// Authoritative native request normalization and existing registrar admission, offline only.
declare(strict_types=1);
require_once dirname(__DIR__).'/paymenter-test-app.php';

$repo = dirname(__DIR__, 2);
$release = paymenterTestApp();
require $release.'/vendor/autoload.php';
foreach (['DomainName', 'RegistrarResult', 'RegistrarClient', 'EnomApi'] as $file) {
    require $repo.'/Enom/Api/'.$file.'.php';
}
$cases = [
    'ordinary' => 'example.com', 'uppercase' => 'EXAMPLE.COM', 'mixed_case' => 'ExAmPlE.CoM',
    'one_character' => 'a.com', 'one_digit' => '7.com', 'digits' => '123.com',
    'label_63' => str_repeat('a', 63).'.com', 'label_64' => str_repeat('a', 64).'.com',
    'middle_hyphen' => 'test-domain.com', 'nonreserved_double_hyphen' => 'a--b.com',
    'reserved_double_hyphen' => 'ab--cd.com', 'leading_hyphen' => '-example.com',
    'trailing_hyphen' => 'example-.com', 'punycode' => 'xn--bcher-kva.com',
    'uppercase_punycode' => 'XN--BCHER-KVA.COM', 'accented_idn' => 'bücher.com',
    'snowman_idn' => '☃.com', 'fullwidth_idn_mapping' => 'Ｅｘａｍｐｌｅ.com',
    'fullwidth_dot_mapping' => 'example．com', 'net' => 'example.net', 'org' => 'example.org',
    'suffix_only' => '.com', 'missing_dot' => 'examplecom', 'missing_name' => 'com',
    'subdomain' => 'www.example.com', 'co_uk' => 'example.co.uk',
    'trailing_dot' => 'example.com.', 'url' => 'https://example.com', 'path' => 'example.com/path',
    'query' => 'example.com?a=b', 'fragment' => 'example.com#x', 'port' => 'example.com:443',
    'email' => 'person@example.com', 'underscore' => 'example_name.com',
    'internal_space' => 'exa mple.com', 'internal_tab' => "exa\tmple.com",
    'internal_nul' => "exa\0mple.com", 'internal_zero_width' => "exa\u{200B}mple.com",
    'empty' => '', 'spaces_only' => '   ', 'ascii_trim' => " \texample.COM\r\n\0\x0b",
    'nbsp_trim' => "\u{00A0}example.COM\u{00A0}",
    'invisible_trim' => "\u{200B}example.COM\u{200B}",
    'astral_invisible_trim' => "\u{1D173}example.COM\u{1D173}",
    'oversized' => str_repeat('a', 254).'.com',
];
$invisible = preg_replace_callback('/\\\\x\{([0-9A-Fa-f]+)\}/',
    fn ($match) => mb_chr(hexdec($match[1])), Illuminate\Support\Str::INVISIBLE_CHARACTERS);
foreach (preg_split('//u', $invisible, -1, PREG_SPLIT_NO_EMPTY) as $index => $character) {
    $cases['native_invisible_boundary_'.$index] = $character.'Example.COM'.$character;
}
foreach ([0x85, 0x1680, 0x2000, 0x2001, 0x2002, 0x2003, 0x2004, 0x2005,
    0x2006, 0x2007, 0x2008, 0x2009, 0x200A, 0x2028, 0x2029, 0x202F, 0x205F, 0x3000] as $point) {
    $character = mb_chr($point);
    $cases['native_whitespace_boundary_'.$point] = $character.'Example.COM'.$character;
}
$vectors = [];
foreach ($cases as $name => $raw) {
    $request = Illuminate\Http\Request::create('/domains/search', 'POST', ['domain' => $raw, 'action' => 'register']);
    (new Illuminate\Foundation\Http\Middleware\TrimStrings)->handle($request,
        fn ($request) => (new Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull)->handle($request, fn () => null));
    $effective = $request->input('domain');
    $syntheticCalls = 0; $canonical = null;
    $api = new Paymenter\Extensions\Servers\Enom\Api\EnomApi(
        new Paymenter\Extensions\Servers\Enom\Api\RegistrarClient('offline', 'offline',
            function ($command, $parameters) use (&$syntheticCalls): string {
                if ($command !== 'Check' || ($parameters['TLD'] ?? null) !== 'com') {
                    throw new RuntimeException('Unexpected offline admission command.');
                }
                $syntheticCalls++;
                return '<interface-response><Command>CHECK</Command><ErrCount>0</ErrCount><Done>true</Done></interface-response>';
            }));
    try {
        if (!is_string($effective) || strlen($effective) > 253) { throw new InvalidArgumentException('Invalid native field.'); }
        $parsed = Paymenter\Extensions\Servers\Enom\Api\DomainName::parse($effective, ['com']);
        $api->search($parsed['domain']);
        $canonical = $parsed['domain'];
    } catch (Throwable) {}
    $vectors[] = ['case' => $name, 'value' => $raw, 'effective' => $effective,
        'backend_rejects' => $syntheticCalls === 0, 'canonical' => $canonical,
        // The current browser contract deliberately admits ordinary ASCII names only.
        'frontend_rejects' => $syntheticCalls === 0 || is_string($effective) && preg_match('/[^\x00-\x7f]/', $effective) === 1,
        'synthetic_admission_calls' => $syntheticCalls];
}
echo json_encode($vectors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
