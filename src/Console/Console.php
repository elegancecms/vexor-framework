<?php

declare(strict_types=1);

namespace Vexor\Console;

use Vexor\Application;

/**
 * Vexor Console Application
 * 
 * CLI command runner similar to Laravel's Artisan.
 * 
 * Features:
 * - Command registration
 * - Argument and option parsing
 * - Colored output
 * - Progress bars
 * - Interactive prompts
 * - Auto-discovery
 */
class Console
{
    private Application $app;
    private array $commands = [];
    private array $builtinCommands = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->registerBuiltins();
    }

    private function registerBuiltins(): void
    {
        $this->register(Commands\MakeModelCommand::class);
        $this->register(Commands\MakeControllerCommand::class);
        $this->register(Commands\MakeMiddlewareCommand::class);
        $this->register(Commands\MakeMigrationCommand::class);
        $this->register(Commands\MigrateCommand::class);
        $this->register(Commands\ServeCommand::class);
        $this->register(Commands\KeyGenerateCommand::class);
        $this->register(Commands\RouteListCommand::class);
        $this->register(Commands\CacheClearCommand::class);
    }

    public function register(string $commandClass): void
    {
        /** @var Command $instance */
        $instance = new $commandClass($this->app, $this);
        $this->commands[$instance->getName()] = $commandClass;
    }

    public function run(array $argv): int
    {
        array_shift($argv); // Remove script name

        if (empty($argv)) {
            $this->listCommands();
            return 0;
        }

        $commandName = array_shift($argv);

        if ($commandName === '--version' || $commandName === '-v') {
            $this->line("Vexor Framework v" . $this->app->version() . " (PHP " . PHP_VERSION . ")");
            return 0;
        }

        if (!isset($this->commands[$commandName])) {
            $this->error("Command [{$commandName}] not found.");
            $this->line("\nDid you mean one of these?");
            $suggestions = $this->suggest($commandName);
            foreach ($suggestions as $s) {
                $this->line("  " . $s);
            }
            return 1;
        }

        try {
            $commandClass = $this->commands[$commandName];
            $command      = new $commandClass($this->app, $this);
            $input        = $this->parseArgs($argv, $command->getDefinition());

            return $command->execute($input, $this);
        } catch (\Throwable $e) {
            $this->error("Error: " . $e->getMessage());
            if ($this->app->config('app.debug')) {
                $this->line($e->getTraceAsString());
            }
            return 1;
        }
    }

    private function parseArgs(array $argv, array $definition): Input
    {
        $args    = [];
        $options = [];
        $argDefs = $definition['arguments'] ?? [];
        $optDefs = $definition['options'] ?? [];

        $argIndex = 0;

        for ($i = 0; $i < count($argv); $i++) {
            $token = $argv[$i];

            if (str_starts_with($token, '--')) {
                $token = substr($token, 2);
                if (str_contains($token, '=')) {
                    [$name, $value]  = explode('=', $token, 2);
                    $options[$name]  = $value;
                } else {
                    $options[$token] = true;
                }
            } elseif (str_starts_with($token, '-')) {
                $name            = substr($token, 1);
                $options[$name]  = $argv[++$i] ?? true;
            } else {
                $argName = array_keys($argDefs)[$argIndex++] ?? "arg{$argIndex}";
                $args[$argName] = $token;
            }
        }

        return new Input($args, $options);
    }

    private function listCommands(): void
    {
        $this->line("\n  <fg=green>Vexor Framework</fg> v" . $this->app->version());
        $this->line("  <fg=yellow>─────────────────────────────────────────</fg>\n");
        $this->line("  <fg=yellow>Usage:</fg>");
        $this->line("    vexor <command> [arguments] [options]\n");
        $this->line("  <fg=yellow>Available Commands:</fg>\n");

        $grouped = [];
        foreach ($this->commands as $name => $class) {
            $instance = new $class($this->app, $this);
            $parts    = explode(':', $name);
            $group    = count($parts) > 1 ? $parts[0] : 'general';

            $grouped[$group][$name] = $instance->getDescription();
        }

        ksort($grouped);

        foreach ($grouped as $group => $cmds) {
            $this->line("  <fg=cyan>{$group}</fg>");
            foreach ($cmds as $name => $desc) {
                $this->line(sprintf("    <fg=green>%-35s</fg> %s", $name, $desc));
            }
            $this->line('');
        }
    }

    private function suggest(string $name): array
    {
        $suggestions = [];
        foreach (array_keys($this->commands) as $cmd) {
            if (str_contains($cmd, $name) || levenshtein($name, $cmd) <= 3) {
                $suggestions[] = $cmd;
            }
        }
        return array_slice($suggestions, 0, 3);
    }

    // ── Output ────────────────────────────────────────────────────────────────

    public function line(string $text): void
    {
        echo $this->formatTags($text) . PHP_EOL;
    }

    public function info(string $text): void
    {
        $this->line("  <fg=blue>ℹ</fg>  {$text}");
    }

    public function success(string $text): void
    {
        $this->line("  <fg=green>✓</fg>  {$text}");
    }

    public function error(string $text): void
    {
        $this->line("  <fg=red>✗</fg>  <fg=red>{$text}</fg>");
    }

    public function warn(string $text): void
    {
        $this->line("  <fg=yellow>⚠</fg>  <fg=yellow>{$text}</fg>");
    }

    public function table(array $headers, array $rows): void
    {
        $widths = array_map('strlen', $headers);
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, strlen((string) $cell));
            }
        }

        $separator = '+' . implode('+', array_map(fn($w) => str_repeat('-', $w + 2), $widths)) . '+';
        $this->line($separator);

        $headerRow = '|';
        foreach ($headers as $i => $header) {
            $headerRow .= ' ' . str_pad($header, $widths[$i]) . ' |';
        }
        $this->line("<fg=green>{$headerRow}</fg>");
        $this->line($separator);

        foreach ($rows as $row) {
            $rowStr = '|';
            foreach (array_values($row) as $i => $cell) {
                $rowStr .= ' ' . str_pad((string) $cell, $widths[$i] ?? 10) . ' |';
            }
            $this->line($rowStr);
        }
        $this->line($separator);
    }

    public function ask(string $question, string $default = ''): string
    {
        echo "  {$question}" . ($default ? " [{$default}]" : '') . ": ";
        $input = trim(fgets(STDIN));
        return $input ?: $default;
    }

    public function confirm(string $question, bool $default = false): bool
    {
        $hint  = $default ? '[Y/n]' : '[y/N]';
        echo "  {$question} {$hint}: ";
        $input = strtolower(trim(fgets(STDIN)));

        if ($input === '') return $default;
        return in_array($input, ['y', 'yes']);
    }

    public function secret(string $question): string
    {
        echo "  {$question}: ";
        system('stty -echo');
        $input = trim(fgets(STDIN));
        system('stty echo');
        echo PHP_EOL;
        return $input;
    }

    private function formatTags(string $text): string
    {
        $colors = [
            'fg=green'  => "\033[32m",
            'fg=red'    => "\033[31m",
            'fg=yellow' => "\033[33m",
            'fg=blue'   => "\033[34m",
            'fg=cyan'   => "\033[36m",
            'fg=white'  => "\033[37m",
        ];

        foreach ($colors as $tag => $code) {
            $text = str_replace("<{$tag}>", $code, $text);
            $text = str_replace("</{$tag}>", "\033[0m", $text);
            $shortTag = explode('=', $tag)[1] ?? '';
            $text = str_replace("</{$shortTag}>", "\033[0m", $text);
        }

        // Remove unresolved tags
        $text = preg_replace('/<[^>]+>/', '', $text);

        return $text . "\033[0m";
    }

    public function getCommands(): array { return $this->commands; }
    public function getApp(): Application { return $this->app; }
}
