<?php

declare(strict_types=1);

namespace Vexor\Core\Http;

use Closure;

interface MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response;
}
