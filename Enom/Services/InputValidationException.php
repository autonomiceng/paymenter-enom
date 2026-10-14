<?php

namespace Paymenter\Extensions\Servers\Enom\Services;

use InvalidArgumentException;

/** Safe local field messages, never provider exceptions or private responses. */
class InputValidationException extends InvalidArgumentException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
