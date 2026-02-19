<?php

declare(strict_types=1);

namespace Vexor\Console;

class Input
{
    private array $arguments;
    private array $options;

    public function __construct(array $arguments = [], array $options = [])
    {
        $this->arguments = $arguments;
        $this->options   = $options;
    }

    public function argument(string $name, mixed $default = null): mixed
    {
        return $this->arguments[$name] ?? $default;
    }

    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    public function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    public function hasArgument(string $name): bool
    {
        return array_key_exists($name, $this->arguments);
    }

    public function all(): array
    {
        return ['arguments' => $this->arguments, 'options' => $this->options];
    }
}
