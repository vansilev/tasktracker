<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanViewBilling
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || (! $user->hasPermission(Permission::ViewBilling) && ! $user->hasPermission(Permission::ManageBilling))) {
            abort(403, __('Access denied.'));
        }

        return $next($request);
    }
}
