<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * خطأ دومين بيرجع بشكل العقد في 06 القسم 1: { message, code, errors? }.
 *
 * مثال: throw new ApiException(401, 'INVALID_CREDENTIALS')
 * لو الـ message فاضية بتتاخد من lang/{locale}/api.php بالـ code.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>|null  $errors
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message = '',
        public readonly ?array $errors = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }
}
