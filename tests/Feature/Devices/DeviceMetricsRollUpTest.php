<?php

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Jobs\RollUpDeviceMetricsJob;
use App\Models\DeviceMetricHourly;
use App\Models\DeviceMetricSample;
use App\Models\NetworkDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * El agregado y la poda son lo que hace sostenible la serie temporal.
 *
 * Con las antenas de cliente dentro del alcance son ~100.000 filas diarias. Sin
 * este job la tabla crece hasta hacer inviables los backups de la VPS, y el
 * fallo se manifiesta meses después, cuando ya hay decenas de millones de filas
 * y borrarlas es en sí mismo una operación peligrosa.
 */
beforeEach(function () {
    config(['queue.default' => 'sync']);
    config(['notifications.queue.connection' => null]);
    config(['cache.default' => 'array']);

    $this->device = NetworkDevice::create([
        'name' => 'Antena', 'vendor' => DeviceVendor::UBIQUITI, 'role' => DeviceRole::BACKHAUL_AP,
        'driver' => 'airos', 'host' => '10.9.0.1', 'username' => 'ubnt', 'password' => 'ubnt',
    ]);
});

function sample(int $deviceId, string $at, array $values = []): void
{
    DB::table('device_metric_samples')->insert(array_merge([
        'device_id'  => $deviceId,
        'sampled_at' => $at,
    ], $values));
}

it('resume una hora en mínimo, media y máximo', function () {
    $hour = now()->subHours(2)->startOfHour();

    sample($this->device->id, $hour->copy()->addMinutes(5)->toDateTimeString(),  ['signal_dbm' => -60, 'ccq_percent' => 95, 'snr_db' => 35]);
    sample($this->device->id, $hour->copy()->addMinutes(20)->toDateTimeString(), ['signal_dbm' => -90, 'ccq_percent' => 40, 'snr_db' => 10]);
    sample($this->device->id, $hour->copy()->addMinutes(40)->toDateTimeString(), ['signal_dbm' => -66, 'ccq_percent' => 90, 'snr_db' => 30]);

    (new RollUpDeviceMetricsJob())->handle();

    $bucket = DeviceMetricHourly::first();

    // El mínimo importa tanto como la media: un enlace que se hunde veinte
    // minutos tiene una media aceptable y es justo el que hay que revisar.
    expect($bucket)->not->toBeNull()
        ->and($bucket->sample_count)->toBe(3)
        ->and($bucket->signal_min_dbm)->toBe(-90)
        ->and($bucket->signal_max_dbm)->toBe(-60)
        ->and($bucket->signal_avg_dbm)->toBe(-72.0)
        ->and($bucket->ccq_min_percent)->toBe(40);
});

it('recalcula en vez de duplicar si el job corre dos veces', function () {
    $hour = now()->subHours(2)->startOfHour();
    sample($this->device->id, $hour->copy()->addMinutes(5)->toDateTimeString(), ['signal_dbm' => -70]);

    (new RollUpDeviceMetricsJob())->handle();
    (new RollUpDeviceMetricsJob())->handle();

    expect(DeviceMetricHourly::count())->toBe(1);
});

it('no resume la hora en curso', function () {
    // Agregar una hora a medias daría un resumen parcial que habría que rehacer.
    sample($this->device->id, now()->startOfHour()->addMinutes(2)->toDateTimeString(), ['signal_dbm' => -70]);

    (new RollUpDeviceMetricsJob())->handle();

    expect(DeviceMetricHourly::count())->toBe(0);
});

it('poda el detalle vencido y conserva el reciente', function () {
    sample($this->device->id, now()->subDays(30)->toDateTimeString(), ['signal_dbm' => -70]);
    sample($this->device->id, now()->subDays(20)->toDateTimeString(), ['signal_dbm' => -71]);
    sample($this->device->id, now()->subDays(2)->toDateTimeString(),  ['signal_dbm' => -72]);

    (new RollUpDeviceMetricsJob())->handle();

    expect(DeviceMetricSample::count())->toBe(1)
        ->and(DeviceMetricSample::first()->signal_dbm)->toBe(-72);
});

it('respeta la retención configurada desde el panel', function () {
    // La fila ya la siembra la migración, igual que en producción: aquí se
    // ajustan sus parámetros, que es lo que haría un operador desde el panel.
    App\Models\AutomationSetting::where('key', RollUpDeviceMetricsJob::SETTING_KEY)
        ->update(['params' => json_encode(['samples_retention_days' => 60, 'hourly_retention_months' => 13])]);
    App\Models\AutomationSetting::flushCache();

    sample($this->device->id, now()->subDays(30)->toDateTimeString(), ['signal_dbm' => -70]);

    (new RollUpDeviceMetricsJob())->handle();

    // Con 60 días de retención, una muestra de hace 30 sobrevive.
    expect(DeviceMetricSample::count())->toBe(1);
});

it('no hace nada si el worker está desactivado', function () {
    App\Models\AutomationSetting::where('key', RollUpDeviceMetricsJob::SETTING_KEY)
        ->update(['enabled' => false]);
    App\Models\AutomationSetting::flushCache();

    sample($this->device->id, now()->subDays(90)->toDateTimeString(), ['signal_dbm' => -70]);

    (new RollUpDeviceMetricsJob())->handle();

    expect(DeviceMetricSample::count())->toBe(1);
});

it('borrar un equipo se lleva sus métricas', function () {
    sample($this->device->id, now()->subHours(2)->toDateTimeString(), ['signal_dbm' => -70]);

    $this->device->delete();

    // Sin el cascade, la poda tendría que arrastrar filas huérfanas para siempre.
    expect(DeviceMetricSample::count())->toBe(0);
});
