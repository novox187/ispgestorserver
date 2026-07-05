<?php

namespace App\Providers;

use App\Models\Client;
use App\Observers\ClientServiceStatusObserver;
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
        // Registra las ventanas de corte del servicio ante cualquier cambio
        // de service_status (automático o manual). Ver el observer para detalles.
        Client::observe(ClientServiceStatusObserver::class);
    }
}
