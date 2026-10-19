<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

/** Narrow PHP 8.4 regression guard; PHP 8.3's parser remains the syntax authority. */
final class Php83Compatibility
{
    private const FUNCTIONS = [
        'array_find', 'array_find_key', 'array_any', 'array_all', 'mb_trim', 'mb_ltrim', 'mb_rtrim',
        'mb_ucfirst', 'mb_lcfirst', 'bcdivmod', 'bcround', 'bcceil', 'bcfloor', 'request_parse_body',
        'http_get_last_response_headers', 'http_clear_last_response_headers', 'fpow',
    ];

    /** @param array<string, string> $sources filename => source, never executed */
    public static function scan(array $sources): array
    {
        $files = []; $defined = [];
        foreach ($sources as $file => $source) {
            $tokens = []; $line = 1;
            foreach (token_get_all($source) as $token) {
                if (is_array($token)) {
                    [$id, $text, $line] = $token;
                    if (!in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $tokens[] = [$id, $text, $line]; }
                    $line += substr_count($text, "\n");
                } else { $tokens[] = [0, $token, $line]; }
            }
            $contexts = []; $scopes = [['namespace' => '', 'imports' => []]];
            $scope = $depth = $round = $namespaceDepth = 0; $classes = []; $classBodies = []; $importTokens = [];
            $properties = []; $attributes = self::attributeConstructors($tokens);
            foreach ($tokens as $i => [$id, $text]) {
                if ($id === T_NAMESPACE && in_array($tokens[$i + 1][0] ?? 0, [T_STRING, T_NAME_QUALIFIED], true)) {
                    $scope = count($scopes); $scopes[] = ['namespace' => strtolower($tokens[$i + 1][1]), 'imports' => []];
                    $namespaceDepth = ($tokens[$i + 2][1] ?? '') === '{' ? $depth + 1 : $depth;
                } elseif ($id === T_NAMESPACE && ($tokens[$i + 1][1] ?? '') === '{') {
                    $scope = count($scopes); $scopes[] = ['namespace' => '', 'imports' => []]; $namespaceDepth = $depth + 1;
                }
                $contexts[$i] = $scope;
                if ($id === T_USE && $depth === $namespaceDepth && ($tokens[$i + 1][1] ?? '') !== '(') {
                    $end = $i + 1;
                    while (isset($tokens[$end]) && $tokens[$end][1] !== ';') { $end++; }
                    for ($j = $i + 1; $j < $end; $j++) { $importTokens[$j] = true; }
                    $scopes[$scope]['imports'] += self::imports(array_slice($tokens, $i + 1, $end - $i - 1));
                }
                if (in_array($id, [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true) && ($tokens[$i - 1][0] ?? 0) !== T_DOUBLE_COLON) {
                    $body = $i + 1;
                    if (($tokens[$body][1] ?? '') === '(') { $body = self::afterBalanced($tokens, $body, '(', ')'); }
                    while (isset($tokens[$body]) && !($tokens[$body][0] === 0 && $tokens[$body][1] === '{')) { $body++; }
                    $classBodies[$body] = true;
                }
                if ($id === T_FUNCTION && !isset($importTokens[$i])) {
                    $name = $i + 1;
                    if (($tokens[$name][1] ?? '') === '&') { $name++; }
                    if (($tokens[$name][0] ?? 0) === T_STRING && (!$classes || $depth !== end($classes)[0])) {
                        $defined[ltrim($scopes[$scope]['namespace'].'\\'.strtolower($tokens[$name][1]), '\\')] = true;
                    }
                }
                if ($id === T_VARIABLE && $classes && [$depth, $round] === end($classes)) { $properties[$i] = true; }
                if ($id === 0 && $text === '(') { $round++; }
                if ($id === 0 && $text === ')') { $round--; }
                if (($id === 0 && $text === '{') || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                    if (isset($classBodies[$i])) { $classes[] = [$depth, $round]; }
                }
                if ($id === 0 && $text === '}') {
                    if ($classes && end($classes)[0] === $depth) { array_pop($classes); }
                    $depth--;
                    if ($depth < $namespaceDepth) { $scope = 0; $namespaceDepth = 0; }
                }
            }
            $files[$file] = [$tokens, $contexts, $scopes, $properties, $attributes];
        }
        $errors = [];
        foreach ($files as $file => [$tokens, $contexts, $scopes, $properties, $attributes]) {
            foreach ($tokens as $i => [$id, $text, $line]) {
                $reason = null; $next = $tokens[$i + 1][1] ?? '';
                if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true) && $next === '(') {
                    $previous = $tokens[$i - 1] ?? [0, ''];
                    $declaration = $previous[0] === T_FUNCTION || ($previous[1] === '&' && ($tokens[$i - 2][0] ?? 0) === T_FUNCTION);
                    if (!$declaration && !isset($attributes[$i]) && !in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
                        $scope = $scopes[$contexts[$i]]; $name = strtolower($text);
                        if ($id === T_STRING) {
                            $local = ltrim($scope['namespace'].'\\'.$name, '\\');
                            $name = $scope['imports'][$name] ?? (isset($defined[$local]) ? $local : $name);
                        } elseif ($id === T_NAME_FULLY_QUALIFIED) { $name = ltrim($name, '\\'); }
                        elseif ($id === T_NAME_RELATIVE) { $name = ltrim($scope['namespace'].'\\'.substr($name, 10), '\\'); }
                        else { $name = ''; } // Qualified calls resolve within user namespaces, never global fallbacks.
                        if (in_array($name, self::FUNCTIONS, true)) { $reason = 'PHP 8.4 function '.$name; }
                    }
                }
                if (in_array(token_name($id), ['T_PUBLIC_SET', 'T_PROTECTED_SET', 'T_PRIVATE_SET'], true)) {
                    $reason = 'PHP 8.4 asymmetric visibility';
                }
                if (in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE], true) && $next === '('
                    && strtolower($tokens[$i + 2][1] ?? '') === 'set' && ($tokens[$i + 3][1] ?? '') === ')') {
                    $reason = 'PHP 8.4 asymmetric visibility';
                }
                if (isset($properties[$i])) {
                    $nested = 0;
                    for ($j = $i + 1; isset($tokens[$j]); $j++) {
                        $part = $tokens[$j][1];
                        if ($tokens[$j][0] !== 0) { continue; }
                        if ($nested === 0 && in_array($part, [';', ',', '}'], true)) { break; }
                        if ($nested === 0 && $part === '{') { $reason = 'PHP 8.4 property hooks'; break; }
                        if (in_array($part, ['(', '['], true)) { $nested++; }
                        if (in_array($part, [')', ']'], true)) { $nested--; }
                    }
                }
                if ($id === T_NEW && self::unwrappedNew($tokens, $i)) { $reason = 'PHP 8.4 unparenthesized new dereference'; }
                if ($reason !== null) { $errors[] = $file.':'.$line.': '.$reason; }
            }
        }
        return $errors;
    }

    private static function imports(array $tokens): array
    {
        $functions = ($tokens[0][0] ?? 0) === T_FUNCTION;
        if ($functions) { array_shift($tokens); }
        $prefix = ''; $items = []; $item = [];
        foreach ($tokens as $token) {
            if ($token[1] === '{') { $prefix = implode('', array_column($item, 1)); $item = []; }
            elseif (in_array($token[1], [',', '}'], true)) { if ($item) { $items[] = $item; } $item = []; }
            else { $item[] = $token; }
        }
        if ($item) { $items[] = $item; }
        $imports = [];
        foreach ($items as $item) {
            $isFunction = $functions || $item[0][0] === T_FUNCTION;
            if ($item[0][0] === T_FUNCTION) { array_shift($item); }
            if (!$isFunction) { continue; }
            $name = ''; $alias = null;
            foreach ($item as $i => $token) {
                if ($token[0] === T_AS) { $alias = $item[$i + 1][1]; break; }
                $name .= $token[1];
            }
            $name = strtolower(ltrim($prefix.$name, '\\'));
            $imports[strtolower($alias ?? substr($name, (int) strrpos('\\'.$name, '\\')))] = $name;
        }
        return $imports;
    }

    /** Only attribute constructors are class names; calls within their arguments remain checked. */
    private static function attributeConstructors(array $tokens): array
    {
        $names = [];
        foreach ($tokens as $start => $token) {
            if ($token[0] !== T_ATTRIBUTE) { continue; }
            $square = 1; $round = 0; $expectName = true;
            for ($i = $start + 1; isset($tokens[$i]) && $square > 0; $i++) {
                [$id, $text] = $tokens[$i];
                if ($expectName && in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                    $names[$i] = true; $expectName = false;
                }
                if ($id === T_ATTRIBUTE || ($id === 0 && $text === '[')) { $square++; }
                if ($id === 0 && $text === ']') { $square--; }
                if ($id === 0 && $text === '(') { $round++; }
                if ($id === 0 && $text === ')') { $round--; }
                if ($id === 0 && $text === ',' && $square === 1 && $round === 0) { $expectName = true; }
            }
        }
        return $names;
    }

    private static function unwrappedNew(array $tokens, int $start): bool
    {
        $i = $start + 1;
        while (($tokens[$i][0] ?? 0) === T_ATTRIBUTE) { $i = self::afterBalanced($tokens, $i, '[', ']'); }
        if (($tokens[$i][1] ?? '') === '(') {
            $i = self::afterBalanced($tokens, $i, '(', ')');
        } elseif (in_array($tokens[$i][0] ?? 0, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_VARIABLE, T_STATIC], true)) {
            $i++;
            // Member access before constructor arguments is part of a dynamic class name.
            while (in_array($tokens[$i][0] ?? 0, [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true) || ($tokens[$i][1] ?? '') === '[') {
                if (($tokens[$i][1] ?? '') === '[') { $i = self::afterBalanced($tokens, $i, '[', ']'); continue; }
                $i++;
                if (($tokens[$i][1] ?? "") === "{") { $i = self::afterBalanced($tokens, $i, "{", "}"); }
                elseif (in_array($tokens[$i][0] ?? 0, [T_STRING, T_VARIABLE], true)) { $i++; }
                else { return false; }
            }
        } elseif (($tokens[$i][0] ?? 0) === T_CLASS) {
            // An anonymous class can have constructor arguments, extends/implements and a body.
            $i++;
            if (($tokens[$i][1] ?? '') === '(') { $i = self::afterBalanced($tokens, $i, '(', ')'); }
            while (isset($tokens[$i]) && !($tokens[$i][0] === 0 && $tokens[$i][1] === '{')) { $i++; }
            $i = self::afterBalanced($tokens, $i, '{', '}');
        } else { return false; }
        if (($tokens[$i][1] ?? '') === '(') { $i = self::afterBalanced($tokens, $i, '(', ')'); }
        return in_array($tokens[$i][0] ?? 0, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
            || ($tokens[$i][1] ?? '') === '[';
    }

    private static function afterBalanced(array $tokens, int $start, string $open, string $close): int
    {
        $depth = 0;
        for ($i = $start; isset($tokens[$i]); $i++) {
            if (($tokens[$i][0] === 0 && $tokens[$i][1] === $open) || ($open === '[' && $tokens[$i][0] === T_ATTRIBUTE)
                || ($open === '{' && in_array($tokens[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; }
            if ($tokens[$i][0] === 0 && $tokens[$i][1] === $close && --$depth === 0) { return $i + 1; }
        }
        return count($tokens);
    }
}
