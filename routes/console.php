<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use App\Services\AutomationSettingsService;
use App\Services\MikroTikQueueSyncService;
use App\Services\MikroTikService;

// ─── Scheduler dinámico ──────────────────────────────────────────────────────
// Todas las automatizaciones se leen desde la tabla `automation_settings`.
// SCHEDULE_TEST_MODE=true fuerza todas a correr cada 5 min (override global).
//
// El try/catch es OBLIGATORIO: durante `composer dump-autoload` en el build de
// Docker se ejecuta `artisan package:discover` que carga este archivo, pero la
// imagen de Composer no tiene pdo_mysql y no hay base de datos disponible.
// Cualquier intento de tocar Schema/DB acá explota el build.

try {
    if (Schema::hasTable('automation_settings')) {
        $testMode = (bool) env('SCHEDULE_TEST_MODE', false);
        app(AutomationSettingsService::class)->applySchedule(app(Schedule::class), $testMode);
    }
} catch (\Throwable $e) {
    // Silencioso: build time o DB offline. En runtime real el scheduler
    // se vuelve a invocar cada minuto y reaplica.
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('mikrotik:sync-queues {--cleanup : Elimina colas que no existan en la base de datos}', function (MikroTikService $mikrotik, MikroTikQueueSyncService $sync) {
    $this->info('Sincronizando colas de MikroTik...');
    $sys = $mikrotik->getSystemInfo();
    if (empty($sys)) {
        $this->error('No hay conexión con MikroTik. Revisa MIKROTIK_HOST/USER/PASS y permisos.');
        return self::FAILURE;
    }
    $writeCheck = $mikrotik->testSimpleQueueWrite();
    if (!$writeCheck['success']) {
        $this->error('La cuenta no puede escribir en /queue/simple. Habilita API y permisos write.');
        $this->line(json_encode($writeCheck, JSON_UNESCAPED_UNICODE));
        return self::FAILURE;
    }
    $cleanup = (bool) $this->option('cleanup');
    $result = $sync->syncQueues($cleanup);
    $plansCount = count($result['plans'] ?? []);
    $clientsCount = count($result['clients'] ?? []);
    $deletedCount = $result['cleanup']['deleted_count'] ?? 0;
    $this->info("Planes procesados: {$plansCount}");
    $this->info("Clientes procesados: {$clientsCount}");
    if ($cleanup) {
        $this->info("Colas eliminadas: {$deletedCount}");
    }
    return self::SUCCESS;
})->purpose('Sincroniza Simple Queues de planes y clientes con MikroTik');
