<?php

namespace App\Services\Uploads;

use RuntimeException;

final class ImageStorageException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 400
    ) {
        parent::__construct($message, $statusCode);
    }
}
