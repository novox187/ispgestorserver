<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withSchedule(function (Schedule $schedule) {
        // Procesar facturación automática diariamente a las 2 AM
        $schedule->command('billing:process')
                 ->dailyAt('02:00')
                 ->timezone('America/New_York') // Ajusta tu zona horaria
                 ->appendOutputTo(storage_path('logs/billing.log'));

        // También ejecutar el día 1 de cada mes como respaldo
        $schedule->command('billing:process')
                 ->monthlyOn(1, '03:00')
                 ->appendOutputTo(storage_path('logs/billing-monthly.log'));
    })
    ->create();