<?php

declare(strict_types=1);

namespace Vexor\Exceptions;

class ModelException extends \RuntimeException
{
    public function __construct(string $message = 'Model error.', int $code = 404, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
