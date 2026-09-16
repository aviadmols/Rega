<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The operator panel looks across every shop on purpose. This is where that is declared.
 */
final class EnterOperatorScope
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenant->enterUnscoped();

        return $next($request);
    }
}
