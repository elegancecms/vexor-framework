<?php

declare(strict_types=1);

namespace App\Controllers;

use Vexor\Core\Http\Controller;
use Vexor\Core\Http\Request;
use Vexor\Core\Http\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->json(['message' => 'Hello from Vexor!']);
    }
}