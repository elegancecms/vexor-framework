<?php declare(strict_types=1);

namespace Vexor\Core\Exceptions;

class HttpException extends \RuntimeException
{
    public function __construct(int $statusCode, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int { return $this->getCode(); }
}
