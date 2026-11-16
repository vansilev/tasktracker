<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The Meetings tab is for admins and roles with «view_meetings»; each meeting then has its own access. */
class EnsureCanViewMeetings
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->hasPermission(Permission::ViewMeetings)) {
            abort(403, __('Access denied.'));
        }

        return $next($request);
    }
}
