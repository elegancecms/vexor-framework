<?php

declare(strict_types=1);

namespace Vexor\Core\Console\Commands;

use Vexor\Core\Console\Command;
use Vexor\Core\Console\Console;
use Vexor\Core\Console\Input;

class KeyGenerateCommand extends Command
{
    public function getName(): string { return 'key:generate'; }
    public function getDescription(): string { return 'Generate a new application encryption key'; }

    public function execute(Input $input, Console $console): int
    {
        $key     = 'base64:' . base64_encode(random_bytes(32));
        $envPath = $this->app->basePath('.env');

        if (file_exists($envPath)) {
            $env = file_get_contents($envPath);
            if (str_contains($env, 'APP_KEY=')) {
                $env = preg_replace('/^APP_KEY=.*/m', "APP_KEY={$key}", $env);
            } else {
                $env .= "\nAPP_KEY={$key}";
            }
            file_put_contents($envPath, $env);
            $this->success('Application key written to .env');
        } else {
            $this->warn('.env file not found. Copy .env.example to .env first.');
        }

        $this->line("  Key: <fg=green>{$key}</fg=green>");
        return 0;
    }
}
