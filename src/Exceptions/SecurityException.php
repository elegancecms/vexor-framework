<?php declare(strict_types=1);

namespace Vexor\Exceptions;

class SecurityException extends \RuntimeException
{
    public function __construct(string $message = 'Security violation.', int $code = 400, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
