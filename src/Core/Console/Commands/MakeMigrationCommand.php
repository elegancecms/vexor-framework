<?php

declare(strict_types=1);

namespace Vexor\Core\Console\Commands;

use Vexor\Core\Console\Command;
use Vexor\Core\Console\Console;
use Vexor\Core\Console\Input;

class MakeMigrationCommand extends Command
{
    public function getName(): string { return 'make:migration'; }
    public function getDescription(): string { return 'Create a new database migration file'; }

    public function getDefinition(): array
    {
        return ['arguments' => ['name' => 'Migration name (e.g. create_users_table)'], 'options' => []];
    }

    public function execute(Input $input, Console $console): int
    {
        $name = $input->argument('name');
        if (!$name) { $this->error('Migration name is required.'); return 1; }

        $timestamp = date('Y_m_d_His');
        $filename  = "{$timestamp}_{$name}.php";
        $path      = $this->app->basePath("database/migrations/{$filename}");

        if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);

        $table = '';
        if (preg_match('/create_(.+)_table/', $name, $m)) $table = $m[1];
        elseif (preg_match('/add_.+_to_(.+)/', $name, $m)) $table = $m[1];

        file_put_contents($path, <<<PHP
<?php

declare(strict_types=1);

use Vexor\Core\ORM\Migration;
use Vexor\Core\ORM\Schema;

return new class extends Migration
{
    public function up(Schema \$schema): void
    {
        \$schema->create('{$table}', function (\$table) {
            \$table->id();
            \$table->timestamps();
        });
    }

    public function down(Schema \$schema): void
    {
        \$schema->drop('{$table}');
    }
};
PHP);

        $this->success("Migration created: database/migrations/{$filename}");
        return 0;
    }
}
