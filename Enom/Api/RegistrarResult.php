<?php

namespace Paymenter\Extensions\Servers\Enom\Api;

final class RegistrarResult implements \JsonSerializable
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $code = null,
        public readonly array $data = [],
    ) {
        if (!in_array($status, ['success', 'pending', 'rejected', 'unknown'], true)) {
            throw new \InvalidArgumentException('Invalid registrar outcome.');
        }
    }

    public function value(string $dotPath, mixed $default = null): mixed
    {
        $value = $this->data;
        foreach (explode('.', strtolower($dotPath)) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }
        return $value;
    }

    public function summary(): array
    {
        return ['status' => $this->status, 'code' => $this->code];
    }

    public function jsonSerialize(): array { return $this->summary(); }
    public function __debugInfo(): array { return $this->summary(); }

    public function confirmedExpiry(): ?\DateTimeImmutable
    {
        return $this->status === 'success'
            ? $this->expiryFromPaths(['registryexpdate', 'domaininfo.registryexpdate', 'expirationdate', 'getdomaininfo.status.expiration', 'expiration', 'getextendinfo.expiration', 'order.orderdetail.expirydatetime'])
            : null;
    }

    public function confirmedPrivacyExpiry(): ?\DateTimeImmutable
    {
        return $this->status === 'success' ? $this->expiryFromPaths(['getwppsinfo.wppsexpdate']) : null;
    }

    private function expiryFromPaths(array $paths): ?\DateTimeImmutable
    {
        $missing = new \stdClass;
        $confirmed = null;
        foreach ($paths as $path) {
            $value = $this->value($path, $missing);
            if ($value === $missing) {
                continue;
            }
            $zone = new \DateTimeZone('America/Los_Angeles');
            if (is_array($value)) {
                $attributes = $value['@attributes'] ?? [];
                if (!is_array($attributes)) {
                    return null;
                }
                if (array_key_exists('utc', $attributes)) {
                    // An explicit UTC attribute is authoritative, including when malformed.
                    $value = $attributes['utc'];
                    $zone = new \DateTimeZone('UTC');
                } else {
                    $value = $value['#text'] ?? null;
                }
            }
            if (!is_string($value) || !($date = self::date($value, $zone))) {
                return null;
            }
            // DomainOperations confirms registrar expiry at UTC calendar-day precision.
            // Different known aliases may contain date-only values or precise timestamps.
            if ($confirmed && $confirmed->format('Y-m-d') !== $date->format('Y-m-d')) {
                return null;
            }
            $confirmed ??= $date;
        }
        return $confirmed;
    }

    private static function date(string $input, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if (!preg_match('/^(?:\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?|\d{1,2}\/\d{1,2}\/\d{4}(?: \d{1,2}:\d{2}(?::\d{2})? (?:AM|PM))?|(?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?) \d{1,2}, \d{4}(?: \d{1,2}:\d{2}(?::\d{2})? (?:AM|PM))?)$/iD', $input)) {
            return null;
        }
        try {
            $date = new \DateTimeImmutable($input, $zone);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
                return null;
            }
            return $date->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }
}
