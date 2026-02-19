<?php

declare(strict_types=1);

namespace Vexor\Http;

use Closure;

interface MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response;
}
