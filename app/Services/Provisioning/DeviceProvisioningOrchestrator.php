<?php

namespace App\Services\Provisioning;

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningTaskStatus;
use App\Enums\ProvisioningTaskType;
use App\Models\DeviceProvisioningSession;
use App\Models\MikrotikRouter;
use App\Models\ProvisioningAgent;
use App\Models\ProvisioningTask;
use App\Models\RouterVpnProfile;
use App\Notifications\Core\Facades\Notify;
use App\Notifications\Messages\DeviceProvisionedNotification;
use App\Notifications\Messages\DeviceProvisioningFailedNotification;
use App\Services\MikrotikHealthChecker;
use App\Services\Provisioning\Vpn\TunnelSpec;
use App\Services\Provisioning\Vpn\VpnDriver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Conduce el alta de un dispositivo de punta a punta.
 *
 * Está escrito como una saga con compensación y no como una transacción porque
 * lo que se modifica vive fuera de la base de datos: una interfaz WireGuard en
 * un router y un peer en el sistema operativo del hosting. Ahí no hay rollback
 * que valga; hay que deshacer explícitamente lo hecho. Cada paso que modifica
 * algo apila su reversión, y un fallo posterior las desapila en orden inverso.
 *
 * `advance()` es el único punto de entrada y es idempotente: se invoca al
 * detectar el equipo y después de cada reporte de un agente. Si hay una tarea
 * en vuelo no hace nada, así que llamarlo de más es inofensivo.
 *
 * Secuencia:
 *   1. identificar               (agente de oficina, por la LAN)
 *   2. comprobar compatibilidad  (servidor, sin tocar el equipo)
 *   3. VPN en el router          → la clave pública solo existe tras este paso
 *   4. peer en el hosting        → necesita esa clave, de ahí el orden
 *   5. verificar ambos extremos
 *   6. endurecer el router       → rotar credenciales y cerrar la API
 *   7. crear la fila y probar el enlace desde dentro del contenedor
 */
class DeviceProvisioningOrchestrator
{
    /** Clave de contexto que marca la sesión como «en reversión». */
    private const CTX_ROLLBACK_REASON = 'rollback_reason';
    private const CTX_ROLLBACK_CODE   = 'rollback_code';
    private const CTX_API_PASSWORD    = 'api_password';
    private const CTX_LAN_CREDENTIALS = 'lan_credentials';
    private const CTX_ROUTER_CREATED  = 'router_created_by_session';

    public function __construct(
        private readonly ProvisioningSettings $settings,
        private readonly ProvisioningTaskDispatcher $dispatcher,
        private readonly ProvisioningAuditor $auditor,
        private readonly DeviceCompatibilityChecker $compatibility,
        private readonly VpnAddressAllocator $allocator,
        private readonly VpnDriver $driver,
        private readonly MikrotikHealthChecker $healthChecker,
    ) {
    }

    public function advance(int $sessionId): void
    {
        $session = DeviceProvisioningSession::with('agent')->find($sessionId);

        if ($session === null || $session->status->isTerminal()) {
            return;
        }

        // Una tarea en vuelo: no hay nada que decidir hasta que reporte.
        if ($session->currentTask() !== null) {
            return;
        }

        try {
            if ($session->contextValue(self::CTX_ROLLBACK_REASON) !== null) {
                $this->continueRollback($session);
                return;
            }

            match ($session->status) {
                ProvisioningStatus::DETECTED            => $this->startIdentification($session),
                ProvisioningStatus::IDENTIFYING         => $this->afterIdentification($session),
                ProvisioningStatus::AWAITING_APPROVAL   => null, // espera acción del administrador
                ProvisioningStatus::PROVISIONING_ROUTER => $this->routerStage($session),
                ProvisioningStatus::PROVISIONING_HOST   => $this->hostStage($session),
                ProvisioningStatus::VERIFYING           => $this->verificationStage($session),
                ProvisioningStatus::HARDENING           => $this->hardeningStage($session),
                default                                 => null,
            };
        } catch (Throwable $e) {
            Log::channel(ProvisioningAuditor::LOG_CHANNEL)->error(
                "[sesión {$session->id}] Excepción avanzando la saga.",
                ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()],
            );

            $this->beginRollback($session, 'ORCHESTRATOR_EXCEPTION', $e->getMessage());
        }
    }

    // ── 1. Identificación ────────────────────────────────────────────────────

    private function startIdentification(DeviceProvisioningSession $session): void
    {
        $session->transitionTo(ProvisioningStatus::IDENTIFYING);

        $this->dispatcher->toProvisioner($session, ProvisioningTaskType::IDENTIFY_DEVICE, [
            'lan_ip'         => $session->lan_ip,
            'mac_address'    => $session->mac_address,
            'link_interface' => $session->link_interface,
            'api_port'       => $this->settings->routerApiPort(),
            // Se envían las candidatas de fábrica: el agente prueba en orden y
            // devuelve cuál funcionó. El payload va cifrado en reposo.
            'credentials'    => $this->settings->factoryCredentials(),
            'checks'         => [
                'wan_reachability'  => true,
                'wireguard_support' => true,
            ],
        ]);
    }

    private function afterIdentification(DeviceProvisioningSession $session): void
    {
        $task = $session->lastTaskOfType(ProvisioningTaskType::IDENTIFY_DEVICE);

        if ($task === null || !$task->status->isTerminal()) {
            return;
        }

        if ($task->status->isFailure()) {
            // Nada que compensar: hasta aquí no se ha escrito una sola línea en
            // el equipo.
            $this->failWithoutRollback(
                $session,
                $task->error_code ?? 'IDENTIFY_FAILED',
                $task->error_message ?? 'No se pudo identificar el dispositivo.',
            );
            return;
        }

        $result = $task->result ?? [];

        $session->forceFill(array_filter([
            'identity'         => $result['identity']         ?? null,
            'board_name'       => $result['board_name']       ?? null,
            'routeros_version' => $result['routeros_version'] ?? null,
            'serial_number'    => $result['serial_number']    ?? null,
            'lan_ip'           => $result['lan_ip']           ?? null,
        ], fn ($v) => $v !== null))->save();

        // Las credenciales que funcionaron se guardan cifradas: los pasos
        // siguientes entran por la LAN con ellas hasta que se rotan al final.
        $session->mergeContext([
            self::CTX_LAN_CREDENTIALS => [
                'username' => $result['credentials']['username'] ?? 'admin',
                'password' => $result['credentials']['password'] ?? '',
            ],
        ]);

        $this->auditor->session($session, ProvisioningAuditor::IDENTIFIED, [
            'wireguard_available' => $result['wireguard_available'] ?? null,
            'wan_reachable'       => $result['wan_reachable']       ?? null,
        ]);

        // Sin salida a internet el router no puede alcanzar el endpoint. Se
        // detecta aquí y no tres pasos más adelante con un handshake que nunca
        // llega.
        if (($result['wan_reachable'] ?? true) === false) {
            $this->rejectIncompatible(
                $session,
                'ROUTER_NO_WAN',
                'El equipo no tiene salida a internet, así que no puede alcanzar el servidor VPN. '
                . 'Verifica que el cable de WAN esté conectado en ether1.',
            );
            return;
        }

        $verdict = $this->compatibility->check(
            $session->routeros_version,
            $session->board_name,
            $result['wireguard_available'] ?? null,
        );

        if (!$verdict['compatible']) {
            $this->rejectIncompatible($session, (string) $verdict['code'], (string) $verdict['reason']);
            return;
        }

        if ($verdict['normalized_version'] !== null) {
            $session->forceFill(['routeros_version' => $verdict['normalized_version']])->save();
        }

        if (!$this->settings->autoApprove()) {
            $session->transitionTo(ProvisioningStatus::AWAITING_APPROVAL);
            return;
        }

        $this->beginRouterStage($session);
    }

    /**
     * Aprobación manual desde el panel. Solo tiene efecto sobre una sesión que
     * está justo esperándola.
     */
    public function approve(DeviceProvisioningSession $session): bool
    {
        if ($session->status !== ProvisioningStatus::AWAITING_APPROVAL) {
            return false;
        }

        $this->auditor->session($session, ProvisioningAuditor::APPROVED);
        $this->beginRouterStage($session);

        return true;
    }

    // ── 3. VPN en el router ──────────────────────────────────────────────────

    private function beginRouterStage(DeviceProvisioningSession $session): void
    {
        $session->transitionTo(ProvisioningStatus::PROVISIONING_ROUTER);
        $this->routerStage($session);
    }

    private function routerStage(DeviceProvisioningSession $session): void
    {
        $task = $session->lastTaskOfType(ProvisioningTaskType::APPLY_ROUTER_VPN);

        if ($task === null) {
            $this->dispatchRouterApply($session);
            return;
        }

        if (!$task->status->isTerminal()) {
            return;
        }

        if ($task->status->isFailure()) {
            $this->beginRollback(
                $session,
                $task->error_code ?? 'ROUTER_APPLY_FAILED',
                $task->error_message ?? 'Falló la configuración de la VPN en el router.',
            );
            return;
        }

        $publicKey = $task->result['router_public_key'] ?? null;

        if (blank($publicKey)) {
            // El paso dice haber terminado bien pero no devolvió la clave. Sin
            // ella no se puede registrar el peer, y algo quedó escrito en el
            // equipo: hay que revertir.
            $this->beginRollback(
                $session,
                'ROUTER_PUBLIC_KEY_MISSING',
                'El router no devolvió su clave pública de WireGuard.',
            );
            return;
        }

        $session->forceFill(['vpn_router_public_key' => $publicKey])->save();

        $this->auditor->session($session, ProvisioningAuditor::ROUTER_APPLIED, [
            'vpn_interface'   => $session->vpn_interface,
            'vpn_assigned_ip' => $session->vpn_assigned_ip,
            'public_key'      => $publicKey,
        ]);

        $session->transitionTo(ProvisioningStatus::PROVISIONING_HOST);
        $this->hostStage($session);
    }

    private function dispatchRouterApply(DeviceProvisioningSession $session): void
    {
        $vpnHost = ProvisioningAgent::activeVpnHost();

        if ($vpnHost === null) {
            // Se comprueba ANTES de tocar el router: empezar sabiendo que el
            // otro extremo no está disponible solo garantiza un rollback.
            $this->failWithoutRollback(
                $session,
                'VPN_HOST_UNAVAILABLE',
                'No hay ningún agente de VPN activo en el hosting. No se inicia el alta.',
            );
            return;
        }

        $spec = $this->buildTunnelSpec($session, $vpnHost);
        $access = $this->routerAccess($session);

        $session->forceFill([
            'vpn_interface'   => $spec->interfaceName,
            'vpn_assigned_ip' => $spec->assignedIp,
            'vpn_endpoint'    => $spec->endpoint(),
        ])->save();

        // La compensación se apila ANTES de mandar la tarea: si el agente
        // aplica parte y muere sin reportar, la reversión ya está registrada.
        $session->pushCompensation(ProvisioningTaskType::ROLLBACK_ROUTER_VPN, [
            'spec'   => $spec->toArray(),
            'access' => ['api_username' => $access['api_username'], 'api_port' => $access['api_port']],
        ]);

        $this->dispatcher->toProvisioner($session, ProvisioningTaskType::APPLY_ROUTER_VPN, [
            'connection' => $this->lanConnection($session),
            'operations' => $this->driver->routerApplyOperations($spec, $access),
            'expect'     => ['router_public_key'],
        ]);
    }

    // ── 4. Peer en el hosting ────────────────────────────────────────────────

    private function hostStage(DeviceProvisioningSession $session): void
    {
        $task = $session->lastTaskOfType(ProvisioningTaskType::APPLY_HOST_PEER);

        if ($task === null) {
            $spec = $this->currentSpec($session);

            $session->pushCompensation(ProvisioningTaskType::ROLLBACK_HOST_PEER, [
                'spec'       => $spec->toArray(),
                'public_key' => $session->vpn_router_public_key,
            ]);

            $this->dispatcher->toVpnHost($session, ProvisioningTaskType::APPLY_HOST_PEER, [
                'operations' => $this->driver->hostApplyOperations(
                    $spec,
                    (string) $session->vpn_router_public_key,
                ),
            ]);
            return;
        }

        if (!$task->status->isTerminal()) {
            return;
        }

        if ($task->status->isFailure()) {
            $this->beginRollback(
                $session,
                $task->error_code ?? 'HOST_APPLY_FAILED',
                $task->error_message ?? 'Falló el registro del peer en el hosting.',
            );
            return;
        }

        $this->auditor->session($session, ProvisioningAuditor::HOST_APPLIED, [
            'peer_allowed_ips' => $this->currentSpec($session)->peerAllowedIps(),
        ]);

        $session->transitionTo(ProvisioningStatus::VERIFYING);
        $this->verificationStage($session);
    }

    // ── 5. Verificación en ambos extremos ────────────────────────────────────

    private function verificationStage(DeviceProvisioningSession $session): void
    {
        $spec = $this->currentSpec($session);

        $routerCheck = $session->lastTaskOfType(ProvisioningTaskType::VERIFY_ROUTER_VPN);

        if ($routerCheck === null) {
            $this->dispatcher->toProvisioner($session, ProvisioningTaskType::VERIFY_ROUTER_VPN, [
                'connection' => $this->lanConnection($session),
                'operations' => $this->driver->routerVerifyOperations($spec),
            ]);
            return;
        }

        if (!$routerCheck->status->isTerminal()) {
            return;
        }

        if ($routerCheck->status->isFailure()) {
            $this->beginRollback(
                $session,
                $routerCheck->error_code ?? 'ROUTER_VERIFY_FAILED',
                $routerCheck->error_message ?? 'El router no confirmó el túnel.',
            );
            return;
        }

        $hostCheck = $session->lastTaskOfType(ProvisioningTaskType::VERIFY_HOST_PEER);

        if ($hostCheck === null) {
            $this->dispatcher->toVpnHost($session, ProvisioningTaskType::VERIFY_HOST_PEER, [
                'operations' => $this->driver->hostVerifyOperations(
                    $spec,
                    (string) $session->vpn_router_public_key,
                ),
            ]);
            return;
        }

        if (!$hostCheck->status->isTerminal()) {
            return;
        }

        if ($hostCheck->status->isFailure()) {
            $this->beginRollback(
                $session,
                $hostCheck->error_code ?? 'HOST_VERIFY_FAILED',
                $hostCheck->error_message ?? 'El hosting no confirmó el túnel.',
            );
            return;
        }

        $this->auditor->session($session, ProvisioningAuditor::VERIFIED, [
            'router_handshake' => $routerCheck->result['handshake_age_seconds'] ?? null,
            'host_handshake'   => $hostCheck->result['handshake_age_seconds']   ?? null,
        ]);

        $session->transitionTo(ProvisioningStatus::HARDENING);
        $this->hardeningStage($session);
    }

    // ── 6. Endurecimiento ────────────────────────────────────────────────────

    private function hardeningStage(DeviceProvisioningSession $session): void
    {
        $task = $session->lastTaskOfType(ProvisioningTaskType::HARDEN_ROUTER);

        if ($task === null) {
            $this->dispatcher->toProvisioner($session, ProvisioningTaskType::HARDEN_ROUTER, [
                'connection' => $this->lanConnection($session),
                'operations' => $this->driver->routerHardenOperations(
                    $this->currentSpec($session),
                    $this->routerAccess($session),
                ),
            ]);
            return;
        }

        if (!$task->status->isTerminal()) {
            return;
        }

        if ($task->status->isFailure()) {
            $this->beginRollback(
                $session,
                $task->error_code ?? 'ROUTER_HARDEN_FAILED',
                $task->error_message ?? 'No se pudieron rotar las credenciales del equipo.',
            );
            return;
        }

        $this->finalize($session);
    }

    // ── 7. Alta y prueba definitiva del enlace ───────────────────────────────

    /**
     * Crea la fila del router y comprueba el enlace desde DENTRO del contenedor.
     *
     * Esta última comprobación es la que de verdad cierra el círculo: no basta
     * con que los dos extremos digan que hay handshake, tiene que ser la
     * aplicación —aislada en Docker— la que alcance al equipo por el túnel con
     * las credenciales rotadas. Es exactamente lo que hará el resto del sistema
     * a partir de ahora.
     *
     * La fila se crea aquí y no antes por la regla «el primer router es
     * primary»: registrarla al empezar dejaría al sistema devolviendo 423 en
     * todas las rutas con `primary_router` si el alta fallase.
     */
    private function finalize(DeviceProvisioningSession $session): void
    {
        $spec     = $this->currentSpec($session);
        $access   = $this->routerAccess($session);
        $existing = MikrotikRouter::findByIdentity($session->serial_number, $session->mac_address);

        $router = $existing ?? new MikrotikRouter();
        $wasNew = $existing === null;

        $router->fill([
            'name'                => $this->routerName($session),
            'host'                => $spec->assignedIp,
            'port'                => $access['api_port'],
            'username'            => $access['api_username'],
            'password'            => $access['api_password'],
            'mac_address'         => $session->mac_address,
            'serial_number'       => $session->serial_number,
            'board_name'          => $session->board_name,
            'routeros_version'    => $session->routeros_version,
            'provisioning_source' => 'auto',
            'provisioned_at'      => now(),
            'is_active'           => true,
        ]);
        $router->save();

        $session->mergeContext([self::CTX_ROUTER_CREATED => $wasNew ? $router->id : null]);

        $health = $this->healthChecker->check($router);

        if (!$health['ok']) {
            // El túnel dice estar montado pero la aplicación no llega. La causa
            // habitual no es la VPN sino el enrutado entre el bridge de Docker
            // y la interfaz wg del host; se dice explícitamente para no mandar
            // a nadie a buscar en el sitio equivocado.
            if ($wasNew) {
                $router->delete();
            }

            $this->beginRollback(
                $session,
                'CONTAINER_CANNOT_REACH_ROUTER',
                'El túnel está levantado en ambos extremos pero la aplicación no alcanza al '
                . "equipo en {$spec->assignedIp}:{$access['api_port']}. Revisa el enrutado entre "
                . 'el bridge de Docker y la interfaz WireGuard del host (cadena FORWARD). '
                . 'Detalle: ' . ($health['error'] ?? 'sin detalle'),
            );
            return;
        }

        $this->persistVpnProfile($session, $router, $spec);

        $router->forceFill([
            'connectivity_status'  => 'connected',
            'last_health_check_at' => now(),
            'last_connected_at'    => now(),
            'consecutive_failures' => 0,
        ])->save();

        $session->markCompleted($router);

        $this->auditor->session($session, ProvisioningAuditor::COMPLETED, [
            'router_id'      => $router->id,
            'router_created' => $wasNew,
            'host'           => $router->host,
            'api_username'   => $router->username,
            'is_primary'     => $router->is_primary,
        ]);

        Notify::dispatch(DeviceProvisionedNotification::build($session, $router));
    }

    private function persistVpnProfile(
        DeviceProvisioningSession $session,
        MikrotikRouter $router,
        TunnelSpec $spec,
    ): void {
        // Un re-aprovisionamiento revoca el perfil anterior para que su
        // dirección vuelva al pool en lugar de quedar retenida para siempre.
        RouterVpnProfile::query()
            ->where('router_id', $router->id)
            ->whereNotNull('assigned_ip')
            ->get()
            ->each(fn (RouterVpnProfile $old) => $old->revoke());

        RouterVpnProfile::create([
            'router_id'         => $router->id,
            'session_id'        => $session->id,
            'driver'            => $this->driver->name(),
            'interface_name'    => $spec->interfaceName,
            'assigned_ip'       => $spec->assignedIp,
            'router_public_key' => (string) $session->vpn_router_public_key,
            'server_public_key' => $spec->serverPublicKey,
            'endpoint_host'     => $spec->endpointHost,
            'endpoint_port'     => $spec->endpointPort,
            'allowed_ips'       => $spec->peerAllowedIps(),
            'keepalive'         => $spec->keepalive,
            'status'            => RouterVpnProfile::STATUS_ACTIVE,
            'last_handshake_at' => now(),
        ]);
    }

    // ── Reversión ────────────────────────────────────────────────────────────

    /**
     * Marca la sesión como «en reversión» y arranca la compensación.
     *
     * La auditoría del fallo se escribe aquí, fuera de cualquier transacción de
     * negocio: si se escribiera dentro, un rollback de base de datos la borraría
     * junto con el resto y el incidente quedaría sin rastro.
     */
    public function beginRollback(DeviceProvisioningSession $session, string $code, string $message): void
    {
        $this->auditor->session($session, ProvisioningAuditor::STEP_FAILED, [
            'error_code'    => $code,
            'error_message' => $message,
            'pending_compensations' => count($session->compensations ?? []),
        ]);

        $session->mergeContext([
            self::CTX_ROLLBACK_CODE   => $code,
            self::CTX_ROLLBACK_REASON => $message,
        ]);

        $session->forceFill([
            'error_code'    => $code,
            'error_message' => $message,
        ])->save();

        $this->continueRollback($session);
    }

    private function continueRollback(DeviceProvisioningSession $session): void
    {
        // Se comprueba primero el resultado de la compensación anterior: una
        // que falla no debe detener las que quedan, o se dejaría residuo en el
        // otro extremo.
        $lastRollback = $session->tasks()
            ->whereIn('type', [
                ProvisioningTaskType::ROLLBACK_ROUTER_VPN->value,
                ProvisioningTaskType::ROLLBACK_HOST_PEER->value,
            ])
            ->orderByDesc('id')
            ->first();

        if ($lastRollback !== null && $lastRollback->status->isFailure()) {
            $this->auditor->session($session, ProvisioningAuditor::COMPENSATED, [
                'compensation'   => $lastRollback->type->value,
                'outcome'        => 'failed',
                'error_message'  => $lastRollback->error_message,
                'manual_cleanup' => true,
            ]);
        } elseif ($lastRollback !== null && $lastRollback->status === ProvisioningTaskStatus::SUCCEEDED) {
            $this->auditor->session($session, ProvisioningAuditor::COMPENSATED, [
                'compensation' => $lastRollback->type->value,
                'outcome'      => 'succeeded',
            ]);
        }

        $next = $session->popCompensation();

        if ($next === null) {
            $this->completeRollback($session);
            return;
        }

        try {
            $this->dispatchCompensation($session, $next['type'], $next['payload']);
        } catch (Throwable $e) {
            // El agente que debería revertir ya no está. Se registra y se sigue
            // desapilando: lo que quede exige limpieza manual y así se dice.
            Log::channel(ProvisioningAuditor::LOG_CHANNEL)->error(
                "[sesión {$session->id}] No se pudo encolar la compensación {$next['type']->value}.",
                ['error' => $e->getMessage()],
            );

            $this->auditor->session($session, ProvisioningAuditor::COMPENSATED, [
                'compensation'   => $next['type']->value,
                'outcome'        => 'not_dispatched',
                'error_message'  => $e->getMessage(),
                'manual_cleanup' => true,
            ]);

            $this->continueRollback($session);
        }
    }

    private function dispatchCompensation(
        DeviceProvisioningSession $session,
        ProvisioningTaskType $type,
        array $payload,
    ): void {
        $specData = $payload['spec'] ?? [];

        if ($type === ProvisioningTaskType::ROLLBACK_HOST_PEER) {
            $this->dispatcher->toVpnHost($session, $type, [
                'operations' => $this->driver->hostRollbackOperations(
                    $this->specFromArray($specData),
                    (string) ($payload['public_key'] ?? $session->vpn_router_public_key),
                ),
            ]);
            return;
        }

        $this->dispatcher->toProvisioner($session, $type, [
            'connection' => $this->lanConnection($session),
            'operations' => $this->driver->routerRollbackOperations(
                $this->specFromArray($specData),
                $this->routerAccess($session),
            ),
        ]);
    }

    private function completeRollback(DeviceProvisioningSession $session): void
    {
        // La dirección vuelve al pool: retenerla tras un alta fallida agotaría
        // la subred a base de intentos.
        RouterVpnProfile::query()
            ->where('session_id', $session->id)
            ->whereNotNull('assigned_ip')
            ->get()
            ->each(fn (RouterVpnProfile $p) => $p->revoke());

        $session->markRolledBack();

        $this->auditor->session($session, ProvisioningAuditor::ROLLED_BACK, [
            'error_code'    => $session->error_code,
            'error_message' => $session->error_message,
        ]);

        Notify::dispatch(DeviceProvisioningFailedNotification::build(
            $session,
            (string) $session->error_code,
            (string) $session->error_message,
            rolledBack: true,
        ));
    }

    /**
     * Fallo en un punto donde todavía no se había modificado nada. No hay
     * compensación que ejecutar, así que la sesión muere en `failed` y no en
     * `rolled_back` — la distinción importa al leer el historial.
     */
    private function failWithoutRollback(
        DeviceProvisioningSession $session,
        string $code,
        string $message,
    ): void {
        $session->markFailed($code, $message);

        $this->auditor->session($session, ProvisioningAuditor::STEP_FAILED, [
            'error_code'    => $code,
            'error_message' => $message,
            'rolled_back'   => false,
        ]);

        Notify::dispatch(DeviceProvisioningFailedNotification::build(
            $session, $code, $message, rolledBack: false,
        ));
    }

    private function rejectIncompatible(
        DeviceProvisioningSession $session,
        string $code,
        string $reason,
    ): void {
        $session->markFailed($code, $reason);

        $this->auditor->session($session, ProvisioningAuditor::REJECTED_INCOMPATIBLE, [
            'error_code'       => $code,
            'error_message'    => $reason,
            'routeros_version' => $session->routeros_version,
            'board_name'       => $session->board_name,
        ]);

        Notify::dispatch(DeviceProvisioningFailedNotification::build(
            $session, $code, $reason, rolledBack: false,
        ));
    }

    public function cancel(DeviceProvisioningSession $session, ?string $reason = null): bool
    {
        if ($session->status->isTerminal()) {
            return false;
        }

        if ($session->hasPendingCompensations()) {
            // Ya se tocó algún extremo: cancelar sin revertir dejaría residuo.
            $this->beginRollback(
                $session,
                'CANCELLED_BY_ADMIN',
                $reason ?? 'Alta cancelada por un administrador.',
            );

            return true;
        }

        $session->forceFill([
            'status'        => ProvisioningStatus::CANCELLED,
            'error_code'    => 'CANCELLED_BY_ADMIN',
            'error_message' => $reason ?? 'Alta cancelada por un administrador.',
            'completed_at'  => now(),
        ])->save();

        $this->auditor->session($session, ProvisioningAuditor::CANCELLED, ['reason' => $reason]);

        return true;
    }

    // ── Construcción del túnel ───────────────────────────────────────────────

    /**
     * El endpoint sale de lo que publica el agente del hosting, salvo que el
     * administrador lo haya fijado a mano en el panel: hay despliegues en los
     * que el host ve una IP privada y el router debe marcar a un nombre público.
     */
    private function buildTunnelSpec(
        DeviceProvisioningSession $session,
        ProvisioningAgent $vpnHost,
    ): TunnelSpec {
        return new TunnelSpec(
            interfaceName:   $this->settings->vpnInterfaceName(),
            assignedIp:      $this->allocator->allocateFor($session),
            prefixLength:    $this->allocator->prefixLength(),
            serverIp:        $this->settings->vpnServerIp(),
            serverPublicKey: (string) $vpnHost->capability('server_public_key'),
            endpointHost:    (string) $this->settings->endpointHost($vpnHost->capability('endpoint_host')),
            endpointPort:    $this->settings->endpointPort($vpnHost->capability('endpoint_port')),
            subnet:          $this->settings->vpnSubnet(),
            keepalive:       $this->settings->keepalive(),
        );
    }

    /**
     * Reconstruye la especificación de una sesión ya en curso, tomando primero
     * lo que quedó apilado en la compensación: si el administrador cambia la
     * subred a mitad de un alta, la reversión debe usar los valores con los que
     * realmente se aplicó, no los nuevos.
     */
    private function currentSpec(DeviceProvisioningSession $session): TunnelSpec
    {
        foreach (array_reverse($session->compensations ?? []) as $entry) {
            if (!empty($entry['payload']['spec'])) {
                return $this->specFromArray($entry['payload']['spec']);
            }
        }

        $vpnHost = ProvisioningAgent::activeVpnHost();

        return $this->buildTunnelSpec($session, $vpnHost ?? new ProvisioningAgent());
    }

    private function specFromArray(array $data): TunnelSpec
    {
        return new TunnelSpec(
            interfaceName:   (string) ($data['interface_name'] ?? $this->settings->vpnInterfaceName()),
            assignedIp:      (string) ($data['assigned_ip'] ?? ''),
            prefixLength:    (int) ($data['prefix_length'] ?? 24),
            serverIp:        (string) ($data['server_ip'] ?? $this->settings->vpnServerIp()),
            serverPublicKey: (string) ($data['server_public_key'] ?? ''),
            endpointHost:    (string) ($data['endpoint_host'] ?? ''),
            endpointPort:    (int) ($data['endpoint_port'] ?? $this->settings->endpointPort()),
            subnet:          (string) ($data['subnet'] ?? $this->settings->vpnSubnet()),
            keepalive:       (int) ($data['keepalive'] ?? $this->settings->keepalive()),
        );
    }

    /**
     * Credenciales dedicadas del equipo. La contraseña se genera una sola vez
     * por sesión y se reutiliza entre el endurecimiento y el alta de la fila,
     * porque tienen que coincidir o el sistema no podría entrar después.
     */
    private function routerAccess(DeviceProvisioningSession $session): array
    {
        $password = $session->contextValue(self::CTX_API_PASSWORD);

        if (blank($password)) {
            $password = Str::password(
                length: $this->settings->generatedPasswordLength(),
                symbols: false,   // RouterOS es quisquilloso con algunos símbolos
            );
            $session->mergeContext([self::CTX_API_PASSWORD => $password]);
        }

        return [
            'api_username' => $this->settings->routerApiUsername(),
            'api_password' => (string) $password,
            'api_port'     => $this->settings->routerApiPort(),
        ];
    }

    /**
     * Cómo entra el agente al equipo por la LAN: con las credenciales de fábrica
     * que funcionaron en la identificación. Siguen siendo válidas hasta el paso
     * de endurecimiento, que es justo el último que las necesita.
     */
    private function lanConnection(DeviceProvisioningSession $session): array
    {
        $credentials = $session->contextValue(self::CTX_LAN_CREDENTIALS, []);

        return [
            'host'     => $session->lan_ip,
            'port'     => $this->settings->routerApiPort(),
            'username' => $credentials['username'] ?? 'admin',
            'password' => $credentials['password'] ?? '',
        ];
    }

    private function routerName(DeviceProvisioningSession $session): string
    {
        $candidate = $session->identity
            ?: $session->board_name
            ?: 'MikroTik';

        $suffix = $session->serial_number
            ? ' · ' . substr($session->serial_number, -6)
            : ' · ' . str_replace(':', '', substr((string) $session->mac_address, -8));

        return Str::limit($candidate . $suffix, 100, '');
    }
}
