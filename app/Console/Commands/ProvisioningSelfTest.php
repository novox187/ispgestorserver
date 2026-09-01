<?php

namespace App\Console\Commands;

use App\Enums\AgentRole;
use App\Models\DeviceProvisioningSession;
use App\Models\ProvisioningAgent;
use App\Services\Provisioning\ProvisioningSettings;
use App\Services\Provisioning\VpnAddressAllocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Comprueba, desde dentro del contenedor, que el alta automática puede operar.
 *
 * Está pensado para ejecutarse antes de enchufar el primer equipo: cada cosa
 * que verifica aquí es una que, de faltar, se manifestaría a mitad de un alta
 * y obligaría a revertir un router ya configurado.
 *
 * No toca ningún equipo ni abre ninguna sesión.
 */
class ProvisioningSelfTest extends Command
{
    protected $signature = 'devices:provision-selftest';

    protected $description = 'Verifica los requisitos del alta automática de dispositivos.';

    /** @var list<string> */
    private array $problems = [];

    /** @var list<string> */
    private array $warnings = [];

    public function handle(ProvisioningSettings $settings, VpnAddressAllocator $allocator): int
    {
        $this->info('Alta automática de dispositivos — verificación');
        $this->newLine();

        $this->checkModuleEnabled($settings);
        $this->checkAgents();
        $this->checkSubnet($settings, $allocator);
        $this->checkQueue();
        $this->checkStuckSessions();

        $this->newLine();

        foreach ($this->warnings as $warning) {
            $this->warn("  ⚠  {$warning}");
        }

        if ($this->problems !== []) {
            $this->newLine();
            $this->error('Problemas que impiden dar de alta un equipo:');
            foreach ($this->problems as $problem) {
                $this->error("  ✗ {$problem}");
            }

            return self::FAILURE;
        }

        $this->info('Todo listo: se puede enchufar un equipo.');

        return self::SUCCESS;
    }

    private function checkModuleEnabled(ProvisioningSettings $settings): void
    {
        if (!$settings->enabled()) {
            $this->problems[] = 'El alta automática está desactivada '
                . '(Configuraciones → Workers → Alta Automática de Dispositivos).';
            return;
        }

        $mode = $settings->autoApprove() ? 'automática' : 'MANUAL (cada alta espera aprobación)';
        $this->line("  ✓ Módulo activo — aprobación {$mode}");
    }

    private function checkAgents(): void
    {
        foreach ([AgentRole::PROVISIONER, AgentRole::VPN_HOST] as $role) {
            $agents = ProvisioningAgent::query()
                ->active()
                ->role($role)
                ->whereNotNull('enrolled_at')
                ->get();

            if ($agents->isEmpty()) {
                $this->problems[] = "No hay ningún agente '{$role->value}' activo y enrolado. "
                    . 'Regístralo en MikroTik → Agentes.';
                continue;
            }

            $online = $agents->filter(fn (ProvisioningAgent $a) => $a->isOnline());

            if ($online->isEmpty()) {
                $last = $agents->max('last_seen_at');
                $this->problems[] = "Ningún agente '{$role->value}' está reportando "
                    . '(último contacto: ' . ($last?->diffForHumans() ?? 'nunca') . ').';
                continue;
            }

            $this->line("  ✓ Agente '{$role->value}': {$online->count()} en línea");

            if ($role === AgentRole::VPN_HOST) {
                $this->checkVpnHostCapabilities($online->first());
            }
        }
    }

    private function checkVpnHostCapabilities(ProvisioningAgent $agent): void
    {
        $required = ['server_public_key', 'endpoint_host', 'endpoint_port', 'interface'];

        foreach ($required as $key) {
            if (blank($agent->capability($key))) {
                $this->problems[] = "El agente de VPN no publica '{$key}': la saga no sabría "
                    . 'qué datos meterle al router.';
                return;
            }
        }

        $this->line(sprintf(
            '  ✓ Túnel del hosting: %s en %s:%s',
            $agent->capability('interface'),
            $agent->capability('endpoint_host'),
            $agent->capability('endpoint_port'),
        ));
    }

    private function checkSubnet(ProvisioningSettings $settings, VpnAddressAllocator $allocator): void
    {
        $subnet = $settings->vpnSubnet();

        try {
            $range = $allocator->hostRange($subnet);
            $next  = $allocator->nextFreeAddress();
        } catch (Throwable $e) {
            $this->problems[] = "Subred VPN inválida o agotada ({$subnet}): {$e->getMessage()}";
            return;
        }

        $capacity = $range['last'] - $range['first'];
        $this->line("  ✓ Subred {$subnet} — próxima dirección libre: {$next} ({$capacity} posibles)");

        if (!$allocator->isWithinSubnet($settings->vpnServerIp())) {
            $this->problems[] = "La IP del servidor ({$settings->vpnServerIp()}) no pertenece "
                . "a la subred {$subnet}: los routers no podrían alcanzarla.";
        }
    }

    private function checkQueue(): void
    {
        // La saga avanza a base de jobs en la cola `provisioning`; sin un worker
        // atendiéndola, una sesión se abriría y se quedaría parada en silencio.
        if (config('queue.default') === 'sync') {
            $this->warnings[] = 'La cola está en modo `sync`: la saga correrá dentro de la '
                . 'petición HTTP. Aceptable en desarrollo, no en producción.';
            return;
        }

        $pending = DB::table('jobs')->where('queue', 'provisioning')->count();

        if ($pending > 10) {
            $this->warnings[] = "Hay {$pending} trabajos esperando en la cola `provisioning`. "
                . 'Comprueba que el worker `worker-provisioning` está corriendo.';
            return;
        }

        $this->line("  ✓ Cola `provisioning` — {$pending} trabajos pendientes");
    }

    private function checkStuckSessions(): void
    {
        $stale = DeviceProvisioningSession::query()
            ->active()
            ->where('created_at', '<', now()->subHour())
            ->count();

        if ($stale > 0) {
            $this->warnings[] = "Hay {$stale} sesiones abiertas desde hace más de una hora. "
                . 'Revísalas en el panel: pueden estar reteniendo direcciones de la subred.';
        }
    }
}
