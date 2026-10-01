<?php
declare(strict_types=1);

namespace CS2\Utils;

use RuntimeException;

final class HttpException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $statusCode
    ) {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
