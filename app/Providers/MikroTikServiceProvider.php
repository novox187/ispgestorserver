<?php
// app/Providers/MikroTikServiceProvider.php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use RouterOS\Client;
use RouterOS\Config;

class MikroTikServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, function ($app) {
            $config = new Config([
                'host' => config('mikrotik.host'),
                'user' => config('mikrotik.user'),
                'pass' => config('mikrotik.pass'),
                'port' => config('mikrotik.port'),
                'timeout' => config('mikrotik.timeout'),
                'attempts' => config('mikrotik.attempts'),
                'delay' => config('mikrotik.delay'),
            ]);

            return new Client($config);
        });
    }

    public function boot(): void
    {
        //
    }
}