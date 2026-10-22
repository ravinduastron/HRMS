<?php

namespace App\Providers;

use App\Http\Middleware\EnforceAccountPolicy;
use Illuminate\Support\Facades\Route;
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
        // Scheme pay settings and employee pay rules (same middleware as routes/web.php)
        Route::middleware(['web', 'auth', EnforceAccountPolicy::class])->group(base_path('routes/pay_settings.php'));
    }
}
