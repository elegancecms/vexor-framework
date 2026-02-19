<?php

declare(strict_types=1);

namespace Vexor\Console\Commands;

use Vexor\Console\Command;
use Vexor\Console\Console;
use Vexor\Console\Input;

class MakeModelCommand extends Command
{
    public function getName(): string { return 'make:model'; }
    public function getDescription(): string { return 'Create a new Eloquent-style model'; }

    public function getDefinition(): array
    {
        return [
            'arguments' => ['name' => 'Model class name'],
            'options'   => ['migration' => 'Create migration', 'm' => 'Alias for --migration'],
        ];
    }

    public function execute(Input $input, Console $console): int
    {
        $name = $input->argument('name');
        if (!$name) { $this->error('Model name is required.'); return 1; }

        $table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)) . 's';
        $path  = $this->app->basePath("app/Models/{$name}.php");
        $dir   = dirname($path);

        if (!is_dir($dir)) mkdir($dir, 0755, true);
        if (file_exists($path)) { $this->warn("Model [{$name}] already exists."); return 1; }

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

namespace App\Models;

use Vexor\ORM\Model;

class {$name} extends Model
{
    protected static string \$table = '{$table}';

    protected array \$fillable = [
        // 'field_name',
    ];

    protected array \$hidden = [
        'password',
        'remember_token',
    ];

    protected array \$casts = [
        // 'is_active' => 'boolean',
    ];
}
PHP);

        $this->success("Model [{$name}] created at app/Models/{$name}.php");

        if ($input->hasOption('migration') || $input->hasOption('m')) {
            $migCmd   = new MakeMigrationCommand($this->app, $console);
            $migInput = new Input(['name' => "create_{$table}_table"], []);
            $migCmd->execute($migInput, $console);
        }

        return 0;
    }
}
