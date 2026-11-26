<?php
declare(strict_types=1);

namespace Core;

/**
 * Runtime HTTP exception convertita unitar in raspuns JSON de Front Controller.
 */
/* Exceptie HTTP controlata (HttpException): Transporta statusCode, errorCode si date extra catre raspunsul JSON. */
final class HttpException extends \RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $errorCode,
        string $message,
        private readonly array $extra = []
    ) {
        parent::__construct($message, $statusCode);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function extra(): array
    {
        return $this->extra;
    }
}
