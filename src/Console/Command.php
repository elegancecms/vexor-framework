<?php

declare(strict_types=1);

namespace Vexor\Console;

use Vexor\Application;

/**
 * Base Command class. All CLI commands extend this.
 */
abstract class Command
{
    protected Application $app;
    protected Console $console;

    public function __construct(Application $app, Console $console)
    {
        $this->app     = $app;
        $this->console = $console;
    }

    abstract public function getName(): string;
    abstract public function getDescription(): string;
    abstract public function execute(Input $input, Console $console): int;

    public function getDefinition(): array
    {
        return [
            'arguments' => [],
            'options'   => [],
        ];
    }

    // Convenience delegates
    protected function line(string $text): void      { $this->console->line($text); }
    protected function info(string $text): void      { $this->console->info($text); }
    protected function success(string $text): void   { $this->console->success($text); }
    protected function error(string $text): void     { $this->console->error($text); }
    protected function warn(string $text): void      { $this->console->warn($text); }
    protected function ask(string $q, string $d = ''): string { return $this->console->ask($q, $d); }
    protected function confirm(string $q, bool $d = false): bool { return $this->console->confirm($q, $d); }
}
