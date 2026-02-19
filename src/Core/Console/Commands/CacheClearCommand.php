<?php

declare(strict_types=1);

namespace Vexor\Core\Console\Commands;

use Vexor\Core\Console\Command;
use Vexor\Core\Console\Console;
use Vexor\Core\Console\Input;

class CacheClearCommand extends Command
{
    public function getName(): string { return 'cache:clear'; }
    public function getDescription(): string { return 'Clear the application cache'; }

    public function execute(Input $input, Console $console): int
    {
        $cachePath = $this->app->basePath('storage/cache');

        if (!is_dir($cachePath)) {
            $this->warn('Cache directory not found.');
            return 0;
        }

        $files = glob($cachePath . DIRECTORY_SEPARATOR . '*');
        $count = 0;

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
                $count++;
            }
        }

        $this->success("Cache cleared. {$count} file(s) removed.");
        return 0;
    }
}
