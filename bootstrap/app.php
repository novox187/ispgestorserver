<?php

use App\Http\Middleware\EnsureEmployeeSuperAdmin;
use App\Http\Middleware\CheckPermission;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // CORS debe correr primero para responder los preflight OPTIONS
        // antes de que cualquier otro middleware (auth, throttle) los rechace
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        $middleware->alias([
            'super_admin' => EnsureEmployeeSuperAdmin::class,
            'permission'  => CheckPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Garantiza que TODAS las respuestas de error incluyan cabeceras CORS,
        // incluso cuando una excepción escapa al middleware HandleCors (errores fatales,
        // timeouts de PHP, fallos en el handler de excepciones, etc.).
        $exceptions->respond(function (
            \Symfony\Component\HttpFoundation\Response $response,
            \Throwable $e,
            \Illuminate\Http\Request $request
        ) {
            $origin = $request->header('Origin');
            if (!$origin) {
                return $response;
            }
            $allowed = config('cors.allowed_origins', ['*']);
            if (in_array('*', $allowed) || in_array($origin, $allowed)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                if (config('cors.supports_credentials', false)) {
                    $response->headers->set('Access-Control-Allow-Credentials', 'true');
                }
            }
            return $response;
        });
    })
    ->withSchedule(function (Schedule $schedule) {
        $tz    = config('billing.timezone');
        $sched = config('billing.schedule');

        // Las automatizaciones con AutomationSetting (suspensión, facturación
        // mensual, sync MikroTik) NO se registran aquí: las gestiona el scheduler
        // dinámico de routes/console.php respetando el flag `enabled`.
        // Registrarlas también aquí duplicaba la ejecución y hacía que la
        // suspensión automática siguiera corriendo aunque estuviese desactivada.

        // Cobro automático diario — intenta pagar facturas próximas a vencer
        $schedule->command('billing:process --process-payments')
                 ->dailyAt($sched['process_payments_time'])
                 ->timezone($tz)
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/billing.log'));

        // Reactivación automática de clientes suspendidos con saldo suficiente
        $schedule->command('billing:reactivate')
                 ->dailyAt($sched['auto_reactivate_time'])
                 ->timezone($tz)
                 ->withoutOverlapping();
    })
    ->create();
