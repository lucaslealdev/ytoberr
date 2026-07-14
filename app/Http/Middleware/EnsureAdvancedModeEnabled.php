<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdvancedModeEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Setting::advancedModeEnabled()) {
            return redirect('/settings')->with('status', 'Enable Advanced Mode in Settings to access this page.');
        }

        return $next($request);
    }
}
