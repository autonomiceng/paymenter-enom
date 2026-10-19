<?php
// Synthetic source fixtures only; no fixture code is executed. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require dirname(__DIR__).'/Support/Php83Compatibility.php';

function compatibilityCheck(bool $passed, string $label): void
{
    if (!$passed) { throw new RuntimeException($label); }
}

$functions = [
    'array_find', 'array_find_key', 'array_any', 'array_all', 'mb_trim', 'mb_ltrim', 'mb_rtrim',
    'mb_ucfirst', 'mb_lcfirst', 'bcdivmod', 'bcround', 'bcceil', 'bcfloor', 'request_parse_body',
    'http_get_last_response_headers', 'http_clear_last_response_headers', 'fpow',
];
foreach ($functions as $function) {
    foreach ([$function, '\\'.strtoupper($function)] as $call) {
        $source = "<?php\nnamespace App;\n".$call.'([]);';
        $errors = Php83Compatibility::scan(['planted.php' => $source]);
        compatibilityCheck(count($errors) === 1 && str_contains($errors[0], 'planted.php:3: PHP 8.4 function '.$function),
            'Must reject planted global/fallback call: '.$call);
    }
    compatibilityCheck(Php83Compatibility::scan([
        'definition.php' => '<?php namespace Custom; function '.$function.'($value) {}',
        'caller.php' => '<?php namespace Custom; '.$function.'([]);',
    ]) === [], 'User function definitions resolve across shipped files: '.$function);
}
foreach ([
    'use function array_any as some; some([]);',
    'use function array_any as some; array_any([]);',
    'use Vendor\\{function array_all as all}; array_all([]);',
    '$item = new class(function () {}) { public function array_any() {} }; array_any([]);',
    'use function array_any; array_any(...);',
    'use function array_any as some; echo "$x}"; some([]);',
    '#[Marker(array_any([]))] class Item {}',
    'use function Vendor\\local as array_any; \\array_any([]);',
    'use function Vendor\\{local, other}; array_any([]);',
] as $body) {
    compatibilityCheck(count(Php83Compatibility::scan(['alias.php' => '<?php namespace App; '.$body])) === 1,
        'Global function aliases/callables must fail');
}
$badSyntax = [
    'split asymmetric tokens' => 'class Item { public private /* gap */ (set) string $value; }',
    'asymmetric visibility' => 'class Item { public private(set) string $value; }',
    'property hooks' => 'class Item { public string $value { get => "value"; } }',
    'property hooks with defaults' => 'class Item { public string $value = "value" { get => $this->value; } }',
    'new array class dereference' => 'new $types[0]()->value;',
    'new static array class dereference' => 'new Factory::$types[0]()->value;',
    'new static dereference' => 'new static()->value;',
    'new closure argument' => 'new class(function () {}) {}->value();',
    'new nested anonymous argument' => 'new class(new class {}) {}->value();',
    'new dynamic member dereference' => 'new $factory->type()->value;',
    'new dynamic static dereference' => 'new Factory::$type()->value;',
    'new dereference' => 'new Item()->value();',
    'new nullsafe dereference' => 'new Item()?->value;',
    'new parenthesized class expression' => 'new ($type)()->value;',
    'new attributed anonymous class' => 'new #[Marker] class {}->value();',
    'new variable class' => 'new $type()->value;',
    'new nested constructor' => 'new Item(make(1, (2)))->value;',
    'new anonymous class' => 'new class { public function value() {} }->value();',
];
foreach ($badSyntax as $label => $body) {
    compatibilityCheck(Php83Compatibility::scan(['syntax.php' => '<?php '.$body]) !== [], 'Must reject '.$label);
}
$clean = <<<'SOURCE'
<?php
namespace Attributes {
    #[bcceil(), \array_any()]
    class Example {}
}
namespace Custom {
    function array_any(array $items): bool { return true; }
    echo "$x}";
    array_any([]);
    \Custom\array_any([]);
    namespace\array_any([]);
}
namespace App {
    use function Custom\array_any;
    array_any([]);
    use function Custom\array_all as every;
    every([]);
    use function Custom\{array_find as find, array_find_key};
    find([]); array_find_key([]);
    use Custom\{function mb_trim, function mb_ltrim as left, Item};
    mb_trim(''); left('');
    Custom\array_all([]);
    $item->array_any([]); $item?->array_any([]); Item::array_any([]);
    (new Item(")", "(", "}"))->value();
    new $types[0](); new Factory::$types[0](); new $factory->types[0]();
    new $factory->type(); new Factory::$type(); new $factory->{$type}();
    (new static())->value; (new class(function () {}) {})->value();
    (new Item())->value(); (new Item(make(1)))->value;
    (new class { public function value() {} })->value();
    // array_any([]); public private(set) $x; new Item()->value();
    $text = 'array_any([]); public $value { get => 1; }';
    #[array_any(), \Custom\array_all()]
    class Item {
        public string $value = '{';
        public function array_any($arg) { return $arg; }
        public function __construct(public string $arg = '{}') {}
    }
    function &array_all() {} // Declaration token shape; never executed.
}
SOURCE;
compatibilityCheck(Php83Compatibility::scan(['clean.php' => $clean]) === [], 'PHP 8.3 and namespaced user functions must pass');

$sources = [];
$root = dirname(__DIR__, 2).'/Enom';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') { $sources[$file->getPathname()] = file_get_contents($file->getPathname()); }
}
compatibilityCheck($sources !== [], 'Shipped PHP discovery cannot be empty');
$errors = Php83Compatibility::scan($sources);
compatibilityCheck($errors === [], implode("\n", $errors));
echo 'PHP 8.3 compatibility: planted fixtures rejected; '.count($sources)." shipped PHP files passed.\n";
