<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DeviceRole;
use App\Enums\DeviceVendor;
use App\Http\Controllers\Controller;
use App\Models\NetworkDevice;
use App\Services\Devices\ConnectivityRecorder;
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
    public function __construct(
        private readonly DeviceDriverRegistry $drivers,
        private readonly ConnectivityRecorder $connectivity,
    ) {
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

        $vendor = DeviceVendor::from($validated['vendor']);

        $validated['driver'] ??= $vendor->defaultDriver();
        // El formulario deja el puerto vacío cuando el operador no tiene por
        // qué saberlo, y ahí no vale caer en el `default` de la columna: es
        // 8728, el de la API de RouterOS, y dejaría la antena inalcanzable.
        $validated['port'] = $validated['port'] ?? $vendor->defaultPort();

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

        // Vaciar el puerto en el formulario significa «el de siempre», no
        // «ninguno»: la columna no admite nulos y el `update` moriría con un
        // error de base de datos delante del operador.
        if (array_key_exists('port', $validated) && $validated['port'] === null) {
            $vendor = DeviceVendor::tryFrom((string) ($validated['vendor'] ?? $device->vendor?->value));
            $validated['port'] = $vendor?->defaultPort() ?? $device->port;
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
     *
     * ## Por qué un sondeo bueno se guarda y uno malo no
     *
     * Cuando responde, el equipo **está** vivo: no hay lectura alternativa de
     * una antena que acaba de entregar su telemetría, así que se anota y la
     * ficha deja de decir «Desconectado» delante de quien acaba de ver lo
     * contrario. Antes esa evidencia se tiraba y el rojo seguía ahí hasta el
     * siguiente ciclo del monitor.
     *
     * Cuando no responde, no se toca nada. Este botón se pulsa sobre todo
     * mientras se ajustan credenciales, y un intento fallido a propósito no
     * debería empujar al equipo hacia una alerta de caída. Decidir que algo está
     * caído es del monitor periódico, que para eso tiene un umbral de fallos
     * seguidos.
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

        if ($result->ok) {
            $this->connectivity->recordUp($device, $result);
        }

        return response()->json(['data' => [
            'ok'       => $result->ok,
            'error'    => $result->error,
            'model'    => $result->model,
            'firmware' => $result->firmware,
            // Para que el panel pueda pintar el estado nuevo sin volver a pedir
            // la lista entera.
            'connectivity_status' => $device->fresh()?->connectivity_status,
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
                'message' => 'Los routers MikroTik se escriben por `/api/mikrotik-routers`, '
                    . 'que es donde vive su plano de control: credenciales, router '
                    . 'primary, CIDR de clientes y gateway.',
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
