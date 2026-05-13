<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain-level error. Thrown by the application/domain layers and translated
 * to HTTP responses by ApiExceptionRenderer.
 *
 * We expose `errorCode` (not `code`) because `code` is already a non-readonly
 * property on the built-in Exception, and Postgres-style error codes are
 * strings, not the integer expected by SPL.
 */
abstract class DomainException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    abstract public function httpStatus(): int;
}
