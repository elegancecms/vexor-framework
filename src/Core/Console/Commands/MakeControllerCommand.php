<?php

declare(strict_types=1);

namespace Vexor\Core\Console\Commands;

use Vexor\Core\Console\Command;
use Vexor\Core\Console\Console;
use Vexor\Core\Console\Input;

class MakeControllerCommand extends Command
{
    public function getName(): string { return 'make:controller'; }
    public function getDescription(): string { return 'Create a new controller class'; }

    public function getDefinition(): array
    {
        return [
            'arguments' => ['name' => 'Controller name'],
            'options'   => ['resource' => 'Generate resource controller', 'api' => 'Generate API resource controller'],
        ];
    }

    public function execute(Input $input, Console $console): int
    {
        $name = $input->argument('name');
        if (!$name) { $this->error('Controller name is required.'); return 1; }

        if (!str_ends_with($name, 'Controller')) $name .= 'Controller';

        $path = $this->app->basePath("app/Controllers/{$name}.php");
        $dir  = dirname($path);

        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (file_exists($path)) { $this->warn("Controller [{$name}] already exists."); return 1; }

        $isResource = $input->hasOption('resource');
        $isApi      = $input->hasOption('api');
        $methods    = $isResource || $isApi ? $this->resourceMethods($isApi) : $this->plainMethods();

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\Controllers;

use Vexor\Core\Http\Controller;
use Vexor\Core\Http\Request;
use Vexor\Core\Http\Response;

class {$name} extends Controller
{
{$methods}
}
PHP);

        $this->success("Controller [{$name}] created at app/Controllers/{$name}.php");
        return 0;
    }

    private function plainMethods(): string
    {
        return <<<'PHP'
    public function index(Request $request): Response
    {
        return $this->json(['message' => 'Hello from Vexor!']);
    }
PHP;
    }

    private function resourceMethods(bool $apiOnly): string
    {
        $methods = <<<'PHP'
    public function index(Request $request): Response
    {
        return $this->json(['data' => []]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            // 'field' => 'required|min:1',
        ]);
        return $this->success($data, 'Created successfully', 201);
    }

    public function show(Request $request): Response
    {
        $id = $request->getAttribute('id');
        return $this->json(['id' => $id]);
    }

    public function update(Request $request): Response
    {
        $id = $request->getAttribute('id');
        return $this->success(['id' => $id], 'Updated successfully');
    }

    public function destroy(Request $request): Response
    {
        return $this->noContent();
    }
PHP;

        if (!$apiOnly) {
            $methods .= <<<'PHP'


    public function create(Request $request): Response
    {
        return $this->view('create');
    }

    public function edit(Request $request): Response
    {
        return $this->view('edit');
    }
PHP;
        }

        return $methods;
    }
}
