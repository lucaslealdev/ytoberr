<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserExists
{
    public function handle(Request $request, Closure $next): Response
    {
        $userCount = User::count();

        if ($userCount === 0 && ! $request->is('setup')) {
            return redirect('/setup');
        }

        if ($userCount > 0 && $request->is('setup')) {
            return redirect('/login');
        }

        return $next($request);
    }
}
