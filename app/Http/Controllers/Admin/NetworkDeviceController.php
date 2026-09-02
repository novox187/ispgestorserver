<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Http\Controllers\Controller;
use App\Models\NetworkDevice;
use App\Services\Devices\DeviceCapability;
use App\Services\Devices\DeviceDriverRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inventario de equipos de red de cualquier fabricante.
 *
 * Convive con `MikrotikRouterController`, que sigue sirviendo al módulo de
 * MikroTik: aquel gobierna el plano de control —primary, credenciales de la API
 * de RouterOS, firewall— y este el parque entero, que es lo que necesitan el
 * monitoreo y el mapa.
 *
 * **Los routers MikroTik son de solo lectura por aquí.** Crear uno exige
 * decisiones que este controlador no toma (quién es el primary, qué pasa con el
 * túnel VPN) y que el alta automática ya resuelve. Dejar dos puertas de escritura
 * sobre la misma fila sería pedir que se separen.
 */
class NetworkDeviceController extends Controller
{
    public function __construct(private readonly DeviceDriverRegistry $drivers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $devices = NetworkDevice::query()
            ->when($request->filled('vendor'), fn ($q) => $q->where('vendor', $request->string('vendor')))
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->boolean('only_infrastructure'), fn ($q) => $q->infrastructure())
            ->orderBy('vendor')
            ->orderBy('name')
            ->get()
            ->map(fn (NetworkDevice $d) => $this->map($d));

        return response()->json(['data' => $devices]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->map(NetworkDevice::findOrFail($id))]);
    }

    /**
     * Alta manual de un equipo por IP.
     *
     * Es la vía prevista para las antenas: el operador teclea dirección y
     * credenciales. El descubrimiento por barrido llega después y termina en
     * este mismo sitio.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        if ($validated['vendor'] === DeviceVendor::MIKROTIK->value) {
            return $this->mikrotikIsReadOnly();
        }

        $validated['driver'] ??= DeviceVendor::from($validated['vendor'])->defaultDriver();

        $device = NetworkDevice::create($validated);

        return response()->json(['data' => $this->map($device)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $device = NetworkDevice::findOrFail($id);

        if ($device->isMikrotik()) {
            return $this->mikrotikIsReadOnly();
        }

        $validated = $request->validate($this->rules(forUpdate: true));

        // No pisar la contraseña guardada si el formulario la envía vacía, que
        // es lo que hace un formulario de edición que no la muestra.
        if (blank($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        $device->update($validated);

        return response()->json(['data' => $this->map($device->fresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        $device = NetworkDevice::findOrFail($id);

        if ($device->isMikrotik()) {
            return $this->mikrotikIsReadOnly();
        }

        $device->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Comprueba en el momento si el equipo responde con las credenciales dadas.
     *
     * Existe para que el operador no dé de alta una antena y se entere al día
     * siguiente, por una alerta, de que tecleó mal la contraseña. Solo funciona
     * si el servidor alcanza al equipo; cuando no, lo dirá el primer ciclo del
     * agente.
     */
    public function test(int $id): JsonResponse
    {
        $device = NetworkDevice::findOrFail($id);
        $driver = $this->drivers->for($device);

        if ($driver === null || !$driver->supports(DeviceCapability::PROBE)) {
            return response()->json([
                'error' => [
                    'code'    => 'DRIVER_UNAVAILABLE',
                    'message' => "No hay driver capaz de sondear «{$device->driver}».",
                ],
            ], 422);
        }

        $result = $driver->probe($device, 8);

        return response()->json(['data' => [
            'ok'       => $result->ok,
            'error'    => $result->error,
            'model'    => $result->model,
            'firmware' => $result->firmware,
        ]]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $forUpdate = false): array
    {
        $required = $forUpdate ? 'sometimes' : 'required';

        return [
            'name'     => [$required, 'string', 'max:100'],
            'vendor'   => [$required, Rule::enum(DeviceVendor::class)],
            'role'     => [$required, Rule::enum(DeviceRole::class)],
            'host'     => [$required, 'string', 'max:255'],
            'port'     => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:60'],
            'password' => ['nullable', 'string', 'max:255'],
            'driver'   => ['nullable', 'string', 'max:30'],
            'description'           => ['nullable', 'string', 'max:500'],
            'is_active'             => ['nullable', 'boolean'],
            'is_monitored'          => ['nullable', 'boolean'],
            'mac_address'           => ['nullable', 'string', 'max:17'],
            'serial_number'         => ['nullable', 'string', 'max:60'],
            'latitude'              => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'             => ['nullable', 'numeric', 'between:-180,180'],
            'agent_id'              => ['nullable', 'integer', 'exists:provisioning_agents,id'],
            'credential_profile_id' => ['nullable', 'integer', 'exists:device_credentials,id'],
        ];
    }

    private function mikrotikIsReadOnly(): JsonResponse
    {
        return response()->json([
            'error' => [
                'code'    => 'MIKROTIK_MANAGED_ELSEWHERE',
                'message' => 'Los routers MikroTik se gestionan desde su propio módulo, '
                    . 'que además decide cuál es el router primary.',
            ],
        ], 422);
    }

    /** @return array<string, mixed> */
    private function map(NetworkDevice $device): array
    {
        return [
            'id'                  => $device->id,
            'name'                => $device->name,
            'vendor'              => $device->vendor?->value,
            'vendor_label'        => $device->vendor?->label(),
            'role'                => $device->role?->value,
            'role_label'          => $device->role?->label(),
            'driver'              => $device->driver,
            'model'               => $device->model,
            'firmware_version'    => $device->firmware_version,
            'host'                => $device->host,
            'port'                => $device->port,
            'username'            => $device->username,
            'description'         => $device->description,
            'is_active'           => (bool) $device->is_active,
            'is_monitored'        => (bool) $device->is_monitored,
            'is_primary'          => (bool) $device->is_primary,
            'mac_address'         => $device->mac_address,
            'serial_number'       => $device->serial_number,
            'latitude'            => $device->latitude,
            'longitude'           => $device->longitude,
            'agent_id'            => $device->agent_id,
            'credential_profile_id' => $device->credential_profile_id,
            'has_radio'           => (bool) $device->role?->hasRadio(),
            'connectivity_status' => $device->connectivity_status,
            'last_signal_dbm'     => $device->last_signal_dbm,
            'last_ccq_percent'    => $device->last_ccq_percent,
            'last_telemetry_at'   => $device->last_telemetry_at?->toIso8601String(),
            'editable'            => !$device->isMikrotik(),
        ];
    }
}
