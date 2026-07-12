<?php

namespace App\Providers;

use App\Models\Video;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Share the pending download queue count with the sidebar so it's
        // visible on every page without a per-request N+1 (single count() query).
        View::composer('components.sidebar', function ($view) {
            $view->with(
                'pendingQueueCount',
                Video::whereIn('status', ['pending', 'downloading'])->count()
            );
        });

        // Throttle POST /login by IP + submitted email combined, so a lockout
        // on one email doesn't block a legitimate user from a different
        // account on the same network, and vice versa.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('email') . '|' . $request->ip());
        });
    }
}
