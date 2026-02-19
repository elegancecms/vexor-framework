<?php

declare(strict_types=1);

namespace Vexor\Core\Console\Commands;

use Vexor\Core\Console\Command;
use Vexor\Core\Console\Console;
use Vexor\Core\Console\Input;

class ServeCommand extends Command
{
    public function getName(): string { return 'serve'; }
    public function getDescription(): string { return 'Start the Vexor development server'; }

    public function getDefinition(): array
    {
        return [
            'arguments' => [],
            'options'   => ['host' => 'Server host (default: 127.0.0.1)', 'port' => 'Server port (default: 8000)'],
        ];
    }

    public function execute(Input $input, Console $console): int
    {
        $host = $input->option('host', '127.0.0.1');
        $port = $input->option('port', '8000');

        $this->line('');
        $this->line('  <fg=green>Vexor development server started</fg=green>');
        $this->line("  Local:   <fg=cyan>http://{$host}:{$port}</fg=cyan>");
        $this->line('  Press Ctrl+C to stop.');
        $this->line('');

        $publicPath = $this->app->basePath('public');
        passthru("php -S {$host}:{$port} -t \"{$publicPath}\"");

        return 0;
    }
}
