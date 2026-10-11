<?php

namespace App\Exceptions;

use Exception;

/**
 * A business-rule error the API reports on purpose, e.g. INVALID_CREDENTIALS or
 * ACCOUNT_SUSPENDED. Services throw it; ApiErrorRenderer turns it into the standard JSON:
 *   { "message": "...", "code": "...", "errors": { ... }, "data": { ... } }
 */
class ApiException extends Exception
{
    /**
     * @param  array<string, array<int, string>>  $errors  optional field errors (like validation)
     * @param  array<string, mixed>  $data  optional facts the app needs to recover, e.g. the new
     *                                      fare (FARE_CHANGED) or the ride already active (Phase 9)
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 400,
        public readonly array $errors = [],
        public readonly array $data = [],
    ) {
        parent::__construct($message);
    }
}
