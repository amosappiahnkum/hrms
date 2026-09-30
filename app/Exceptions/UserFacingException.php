<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An error whose message is written for the end user and is safe to return in API responses.
 * Anything else caught in a controller is logged and replaced with a generic message.
 */
class UserFacingException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
