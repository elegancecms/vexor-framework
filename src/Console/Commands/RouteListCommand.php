<?php

declare(strict_types=1);

namespace Vexor\Console\Commands;

use Vexor\Console\Command;
use Vexor\Console\Console;
use Vexor\Console\Input;

class RouteListCommand extends Command
{
    public function getName(): string { return 'route:list'; }
    public function getDescription(): string { return 'List all registered routes'; }

    public function execute(Input $input, Console $console): int
    {
        $router = $this->app->make('router');

        $routesFile = $this->app->basePath('routes/web.php');
        if (file_exists($routesFile)) require $routesFile;

        $apiFile = $this->app->basePath('routes/api.php');
        if (file_exists($apiFile)) require $apiFile;

        $routes = $router->getRoutes();

        if (empty($routes)) {
            $this->warn('No routes registered.');
            return 0;
        }

        $rows = [];
        foreach ($routes as $route) {
            $action = $route->getAction();
            if (is_string($action)) $actionStr = $action;
            elseif (is_array($action)) $actionStr = implode('@', $action);
            else $actionStr = 'Closure';

            $rows[] = [
                'Methods'    => implode('|', $route->getMethods()),
                'URI'        => $route->getUri(),
                'Name'       => $route->getName() ?? '-',
                'Action'     => $actionStr,
                'Middleware' => implode(', ', $route->getMiddleware()) ?: '-',
            ];
        }

        $console->table(['Methods', 'URI', 'Name', 'Action', 'Middleware'], $rows);
        return 0;
    }
}
