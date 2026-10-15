<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use Paymenter\Extensions\Servers\Enom\Api\RegistrarResult;

/**
 * Extra registrant details some registries require (for example .us nexus), read from Enom's
 * GetExtAttributes and stored on the ending. One schema drives the order form and validation.
 *
 * Schema entry: {name, label, required, child, text, options: [{value, label, requires: [names]}]}.
 * A child field is required only when the chosen option of another field lists it in `requires`.
 */
final class RegistryFields
{
    /** @return list<array> normalized schema; [] when the ending needs nothing */
    public static function normalize(RegistrarResult $result): array
    {
        $attributes = $result->value('attributes.attribute');
        if ($attributes === null || $attributes === '') { return []; }
        if (is_array($attributes) && !array_is_list($attributes)) { $attributes = [$attributes]; }
        if (!is_array($attributes)) { throw new \InvalidArgumentException('Unreadable registry fields.'); }
        $schema = [];
        foreach (is_array($attributes) ? $attributes : [] as $attribute) {
            // A field we can't read safely makes the whole ending unsupported rather than silently optional.
            if (!is_array($attribute) || !is_string($attribute['name'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $attribute['name'])
                || self::reserved($attribute['name'])) {
                throw new \InvalidArgumentException('Unreadable registry field.');
            }
            $name = $attribute['name'];
            $options = $attribute['options']['option'] ?? [];
            if (is_array($options) && !array_is_list($options)) { $options = [$options]; }
            $choices = [];
            foreach (is_array($options) ? $options : [] as $option) {
                if (!is_array($option) || !is_string($option['value'] ?? null) || $option['value'] === '') { continue; }
                if (isset($option['requires']) && !is_array($option['requires']) && trim((string) $option['requires']) !== '') {
                    throw new \InvalidArgumentException('Unreadable registry field dependency.');
                }
                $requires = is_array($option['requires'] ?? null) ? ($option['requires']['attribute'] ?? []) : [];
                if (is_array($requires) && !array_is_list($requires)) { $requires = [$requires]; }
                if (!is_array($requires) || array_filter($requires, fn ($item) => !is_array($item) || !is_string($item['name'] ?? null))) {
                    throw new \InvalidArgumentException('Unreadable registry field dependency.');
                }
                $choices[] = ['value' => $option['value'], 'label' => self::text($option['title'] ?? null) ?: $option['value'],
                    'description' => self::text($option['description'] ?? null),
                    'requires' => array_values(array_filter(array_map(fn ($item) => is_array($item) && is_string($item['name'] ?? null) ? $item['name'] : null, is_array($requires) ? $requires : [])))];
            }
            $entry = ['name' => $name, 'label' => self::text($attribute['description'] ?? null) ?: ucfirst(str_replace('_', ' ', $name)),
                'required' => (string) ($attribute['required'] ?? '0') === '1', 'child' => (string) ($attribute['ischild'] ?? '0') === '1',
                'text' => strcasecmp((string) ($attribute['userdefined'] ?? ''), 'true') === 0, 'options' => $choices];
            // Some endings list one field several times; keep the first, merge its options.
            $existing = array_search($name, array_column($schema, 'name'), true);
            if ($existing === false) { $schema[] = $entry; }
            elseif ($choices && !$schema[$existing]['options']) { $schema[$existing]['options'] = $choices; }
        }
        $names = array_column($schema, 'name');
        foreach ($schema as $field) {
            foreach ($field['options'] as $option) {
                foreach ($option['requires'] as $required) {
                    if (!in_array($required, $names, true)) { throw new \InvalidArgumentException('Registry field depends on an unknown field.'); }
                }
            }
        }
        return $schema;
    }

    /** Names that would collide with ordinary Enom request parameters. */
    private static function reserved(string $name): bool
    {
        return (bool) preg_match('/^(?:uid|pw|command|responsetype|sld|tld|numyears|overrideorder|usewiretransfer|deleteorderdetail|premiumdomain|chargeamount|creditcard[a-z]*|cvv2|ccname|ns\d+|usedns|renewname|renew|emailnotify|usecreditcard|queueorder|allowqueuing|ignorensfail|unlockregistrar|lock|authinfo\d*|ordertype|domaincount|preconfig|customersuppliedprice|(?:registrant|admin|tech|auxbilling|billing)[a-z]+)$/i', $name);
    }

    /** Whether every field the registry needs can be collected by the order form. */
    public static function supported(array $schema): bool
    {
        foreach ($schema as $field) {
            if (!$field['text'] && !$field['options']) { return false; }
        }
        return true;
    }

    /**
     * Check client input against the schema. Returns only known, non-empty fields.
     *
     * @throws InputValidationException with keys "registry.<name>"
     */
    public static function validate(array $schema, mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        $values = [];
        foreach ($schema as $field) {
            $value = $input[$field['name']] ?? null;
            $values[$field['name']] = is_string($value) ? trim($value) : '';
        }
        $needed = [];
        foreach ($schema as $field) {
            foreach ($field['options'] as $option) {
                if ($values[$field['name']] === $option['value']) { array_push($needed, ...$option['requires']); }
            }
        }
        $errors = [];
        $clean = [];
        foreach ($schema as $field) {
            $name = $field['name'];
            $value = $values[$name];
            $required = $field['child'] ? in_array($name, $needed, true) : $field['required'];
            if ($field['child'] && !in_array($name, $needed, true)) { continue; } // Not asked for by the chosen options.
            if ($value === '') {
                if ($required) { $errors['registry.'.$name] = $field['options'] ? 'Choose an option.' : 'Fill in this field.'; }
                continue;
            }
            if ($field['options'] && !in_array($value, array_column($field['options'], 'value'), true)) {
                $errors['registry.'.$name] = 'Choose one of the listed options.';
                continue;
            }
            if (strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                $errors['registry.'.$name] = 'Shorten this entry and remove special characters.';
                continue;
            }
            $clean[$name] = $value;
        }
        if ($errors) { throw new InputValidationException($errors); }
        return $clean;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/', ' ', $value)) : '';
    }
}
