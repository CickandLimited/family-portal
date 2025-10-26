<?php

namespace App\Services;

use RuntimeException;

final class ImageProcessingException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 400
    ) {
        parent::__construct($message, $statusCode);
    }
}
