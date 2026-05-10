<?php

use App\Jobs\GenerateMonthlyInvoices;
use App\Jobs\ProcessClientSuspension;
use App\Jobs\SyncMikroTikQueues;
use App\Http\Middleware\EnsureEmployeeSuperAdmin;
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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withSchedule(function (Schedule $schedule) {
        $tz    = config('billing.timezone');
        $sched = config('billing.schedule');

        // Día configurable del mes — generación de facturas mensuales
        $schedule->job(new GenerateMonthlyInvoices())
                 ->monthlyOn($sched['generate_invoices_day'], $sched['generate_invoices_time'])
                 ->timezone($tz)
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/invoices.log'));

        // Cobro automático diario — intenta pagar facturas próximas a vencer
        $schedule->command('billing:process --process-payments')
                 ->dailyAt($sched['process_payments_time'])
                 ->timezone($tz)
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/billing.log'));

        // Corte automático de morosos (con días de gracia configurables)
        $schedule->job(new ProcessClientSuspension(), config('billing.queue.suspensions'))
                 ->dailyAt($sched['auto_suspend_time'])
                 ->timezone($tz)
                 ->withoutOverlapping()
                 ->appendOutputTo(storage_path('logs/suspensions.log'));

        // Reactivación automática de clientes suspendidos con saldo suficiente
        $schedule->command('billing:reactivate')
                 ->dailyAt($sched['auto_reactivate_time'])
                 ->timezone($tz)
                 ->withoutOverlapping();

        // Sincronización de colas MikroTik (dos veces al día)
        $schedule->job(new SyncMikroTikQueues(), 'default')
                 ->twiceDaily(...$sched['mikrotik_sync_hours'])
                 ->timezone($tz)
                 ->withoutOverlapping();
    })
    ->create();
