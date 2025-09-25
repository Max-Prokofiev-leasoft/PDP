<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

class EnsureDatabaseIsMigrated
{
    /**
     * Handle an incoming request.
     *
     * If running locally and the "pdps" table does not exist (fresh clone),
     * run migrations automatically to avoid 500 errors on first load.
     */
    public function handle(Request $request, Closure $next)
    {
        // Only attempt auto-migrate in local environment
        if (app()->environment('local')) {
            try {
                if (!Schema::hasTable('pdps')) {
                    // Ensure connection is set up; run migrations once
                    Artisan::call('migrate', ['--force' => true]);
                }
            } catch (\Throwable $e) {
                // Do not block the request if check fails; continue and let normal error handling work
            }
        }

        return $next($request);
    }
}
