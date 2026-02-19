<?php

declare(strict_types=1);

namespace Vexor\Console\Commands;

use Vexor\Console\Command;
use Vexor\Console\Console;
use Vexor\Console\Input;

class MakeMiddlewareCommand extends Command
{
    public function getName(): string { return 'make:middleware'; }
    public function getDescription(): string { return 'Create a new middleware class'; }

    public function getDefinition(): array
    {
        return ['arguments' => ['name' => 'Middleware class name'], 'options' => []];
    }

    public function execute(Input $input, Console $console): int
    {
        $name = $input->argument('name');
        if (!$name) { $this->error('Middleware name is required.'); return 1; }

        $path = $this->app->basePath("app/Middleware/{$name}.php");
        $dir  = dirname($path);

        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (file_exists($path)) { $this->warn("Middleware [{$name}] already exists."); return 1; }

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\Middleware;

use Closure;
use Vexor\Http\Request;
use Vexor\Http\Response;
use Vexor\Http\MiddlewareInterface;

class {$name} implements MiddlewareInterface
{
    public function handle(Request \$request, Closure \$next): Response
    {
        // Before request processing...

        \$response = \$next(\$request);

        // After response...

        return \$response;
    }
}
PHP);

        $this->success("Middleware [{$name}] created at app/Middleware/{$name}.php");
        return 0;
    }
}
