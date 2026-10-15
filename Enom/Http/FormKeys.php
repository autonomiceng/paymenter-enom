<?php

namespace Paymenter\Extensions\Servers\Enom\Http;

use Illuminate\Http\Request;

/** Session-bound intent keys; route session locks serialize duplicate submissions. */
final class FormKeys
{
    public static function issue(Request $request, string $context): string
    {
        $keys = $request->session()->get('enom.form_keys', []);
        $keys = array_filter($keys, fn ($entry) => is_array($entry) && ($entry['expires'] ?? 0) > time());
        if (!isset($keys[$context])) {
            // A domain page issues about 20 keys; keep room for many open pages before evicting the oldest.
            if (count($keys) >= 400) {
                uasort($keys, fn ($a, $b) => $a['expires'] <=> $b['expires']);
                array_shift($keys);
            }
            $keys[$context] = ['key' => bin2hex(random_bytes(32)), 'expires' => time() + 3600];
        }
        $request->session()->put('enom.form_keys', $keys);

        return $keys[$context]['key'];
    }

    public static function consume(Request $request, string $context): void
    {
        $keys = $request->session()->get('enom.form_keys', []);
        unset($keys[$context]);
        $request->session()->put('enom.form_keys', $keys);
    }

    public static function verify(Request $request, string $context): string
    {
        $entry = $request->session()->get('enom.form_keys', [])[$context] ?? null;
        $input = $request->input('_request_key');
        abort_unless(is_array($entry) && ($entry['expires'] ?? 0) > time()
            && is_string($input) && hash_equals($entry['key'], $input), 419);

        return $input;
    }
}
