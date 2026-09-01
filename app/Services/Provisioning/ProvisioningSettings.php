<?php

namespace App\Services\Provisioning;

use App\Models\AutomationSetting;

/**
 * Lee los parámetros operativos del aprovisionamiento.
 *
 * Un único sitio donde se resuelve la precedencia
 * «AutomationSetting → config/provisioning.php», para que ni los servicios ni
 * los controladores tengan que recordar cuál manda. Los valores editables
 * desde el panel ganan; `config` solo aporta el respaldo si la fila todavía no
 * está sembrada.
 */
class ProvisioningSettings
{
    /** Clave de la fila en `automation_settings`. */
    public const SETTING_KEY = 'device_auto_provisioning';

    public function enabled(): bool
    {
        if (!config('provisioning.enabled', true)) {
            return false;
        }

        $setting = AutomationSetting::getCached(self::SETTING_KEY);

        return $setting === null ? true : (bool) $setting->enabled;
    }

    public function autoApprove(): bool
    {
        return (bool) $this->param('auto_approve', config('provisioning.defaults.auto_approve'));
    }

    public function vpnSubnet(): string
    {
        return (string) $this->param('vpn_subnet', config('provisioning.defaults.vpn_subnet'));
    }

    public function vpnServerIp(): string
    {
        return (string) $this->param('vpn_server_ip', config('provisioning.defaults.vpn_server_ip'));
    }

    /**
     * Puerto del servidor WireGuard. Como el host, gana lo configurado a mano
     * en el panel; si no, lo que publica el agente; y si tampoco, el defecto.
     */
    public function endpointPort(mixed $fallback = null): int
    {
        $configured = $this->param('endpoint_port');

        if (filled($configured)) {
            return (int) $configured;
        }

        if (filled($fallback)) {
            return (int) $fallback;
        }

        return (int) config('provisioning.defaults.endpoint_port');
    }

    /**
     * Host público al que marca el router. Si no se fija a mano, se toma el que
     * publica el propio agente `vpn_host` en sus capabilities: hay despliegues
     * en los que el host ve una IP privada y el router debe marcar a un nombre
     * público que solo el administrador conoce.
     */
    public function endpointHost(mixed $fallback = null): ?string
    {
        $configured = $this->param('endpoint_host');

        return blank($configured) ? ($fallback === null ? null : (string) $fallback) : (string) $configured;
    }

    public function keepalive(): int
    {
        return (int) $this->param('keepalive', config('provisioning.vpn.keepalive_seconds'));
    }

    /**
     * Prefijos OUI admitidos, normalizados a mayúsculas con dos puntos.
     *
     * @return list<string>
     */
    public function allowedMacPrefixes(): array
    {
        $raw = $this->param('allowed_mac_prefixes', config('provisioning.defaults.allowed_mac_prefixes', []));

        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        return array_values(array_filter(array_map(
            fn ($p) => strtoupper(str_replace('-', ':', trim((string) $p))),
            is_array($raw) ? $raw : [],
        )));
    }

    /**
     * ¿Es este equipo candidato a un alta automática?
     *
     * Con la lista vacía se acepta cualquier MAC — configuración deliberada
     * para bancos de pruebas, no un descuido.
     */
    public function macIsAllowed(?string $mac): bool
    {
        $prefixes = $this->allowedMacPrefixes();

        if ($prefixes === [] || $mac === null) {
            return true;
        }

        $normalized = strtoupper(str_replace('-', ':', $mac));

        foreach ($prefixes as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Credenciales de fábrica que el agente probará al identificar el equipo.
     *
     * @return list<array{username: string, password: string}>
     */
    public function factoryCredentials(): array
    {
        $raw = $this->param('factory_credentials', config('provisioning.defaults.factory_credentials', []));

        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry) || !isset($entry['username'])) {
                continue;
            }
            $out[] = [
                'username' => (string) $entry['username'],
                'password' => (string) ($entry['password'] ?? ''),
            ];
        }

        return $out;
    }

    public function minimumRouterOsVersion(): string
    {
        return (string) config('provisioning.vpn.minimum_routeros_version', '7.1');
    }

    public function vpnInterfaceName(): string
    {
        return (string) config('provisioning.vpn.interface_name', 'wg-ispgestor');
    }

    public function routerApiUsername(): string
    {
        return (string) config('provisioning.router.api_username', 'ispgestor-api');
    }

    public function routerApiPort(): int
    {
        return (int) config('provisioning.router.api_port', 8728);
    }

    public function generatedPasswordLength(): int
    {
        return max(16, (int) config('provisioning.router.generated_password_length', 32));
    }

    private function param(string $name, mixed $default = null): mixed
    {
        return AutomationSetting::getParam(self::SETTING_KEY, $name, $default);
    }
}
