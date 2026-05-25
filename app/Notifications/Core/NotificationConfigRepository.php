<?php

namespace App\Notifications\Core;

use App\Models\NotificationChannelConfig;
use App\Models\NotificationEventRoute;
use App\Notifications\Core\Enums\NotificationCategory;
use Illuminate\Support\Facades\Schema;

/**
 * Unifica la configuración del módulo: lee primero de la base de datos (editable
 * desde el panel) y cae a config/notifications.php (env / archivo) como fallback.
 *
 * Este repositorio es el único componente que conoce la regla de precedencia.
 * El resto del módulo lo consulta y nunca toca config() directamente para datos
 * que el admin pueda haber sobreescrito.
 */
class NotificationConfigRepository
{
    /**
     * Devuelve la configuración mergeada de un canal:
     *   - enabled: DB > config
     *   - credentials: DB > config (en config viven los valores de env)
     *   - settings:    DB > config (merge superficial, con DB ganando)
     *
     * @return array{enabled:bool, driver:?string, credentials:array, settings:array}
     */
    public function channelConfig(string $channelKey): array
    {
        $fileChannel = (array) config("notifications.channels.$channelKey", []);

        $fileEnabled     = (bool) ($fileChannel['enabled'] ?? false);
        $driver          = $fileChannel['driver'] ?? null;
        $fileCredentials = $this->extractCredentialsFromFile($channelKey, (array) ($fileChannel['config'] ?? []));
        $fileSettings    = $this->extractSettingsFromFile($channelKey, (array) ($fileChannel['config'] ?? []));

        if (!$this->tableExists('notification_channel_configs')) {
            return [
                'enabled'     => $fileEnabled,
                'driver'      => $driver,
                'credentials' => $fileCredentials,
                'settings'    => $fileSettings,
            ];
        }

        $dbRow = NotificationChannelConfig::forChannel($channelKey);

        if (!$dbRow) {
            return [
                'enabled'     => $fileEnabled,
                'driver'      => $driver,
                'credentials' => $fileCredentials,
                'settings'    => $fileSettings,
            ];
        }

        $dbCredentials = (array) ($dbRow->credentials ?? []);
        $dbSettings    = (array) ($dbRow->settings ?? []);

        return [
            'enabled'     => (bool) $dbRow->enabled,
            'driver'      => $driver,
            // Las credenciales no se mergean — cualquier valor en DB sustituye a env.
            // Esto evita inconsistencias parciales (token DB + chat_id env).
            'credentials' => !empty($dbCredentials) ? $dbCredentials : $fileCredentials,
            'settings'    => array_merge($fileSettings, $dbSettings),
        ];
    }

    /**
     * Lista de rutas para una categoría. Si DB tiene rutas habilitadas → ganan.
     * Si no, retorna las rutas de severidad declaradas en config.
     *
     * @return array<int, array{channel:string,address:string,metadata:array}>
     */
    public function routesForCategory(NotificationCategory $category, string $severity): array
    {
        if ($this->tableExists('notification_event_routes')) {
            $dbRoutes = NotificationEventRoute::cached()
                ->where('category', $category->value)
                ->where('enabled', true)
                ->values();

            if ($dbRoutes->isNotEmpty()) {
                $resolved = [];
                foreach ($dbRoutes as $route) {
                    $channelCfg = $this->channelConfig($route->channel_key);
                    if (!$channelCfg['enabled']) {
                        continue;
                    }

                    $address = $route->address_override
                        ?: $this->defaultAddressForSeverity($route->channel_key, $severity);

                    if (!is_string($address) || $address === '') {
                        continue;
                    }

                    $resolved[] = [
                        'channel'  => $route->channel_key,
                        'address'  => $address,
                        'metadata' => (array) ($route->extra ?? []),
                    ];
                }

                if (!empty($resolved)) {
                    return $resolved;
                }
            }
        }

        // Fallback: ruteo declarativo en config/notifications.php
        $overrides = config('notifications.category_overrides', []);
        $byCategory = $overrides[$category->value] ?? null;
        if (is_array($byCategory) && !empty($byCategory)) {
            return $this->filterEnabled($byCategory);
        }

        $bySeverity = config("notifications.severity_routes.$severity", []);
        return $this->filterEnabled(is_array($bySeverity) ? $bySeverity : []);
    }

    /**
     * @param array<int,array> $destinations
     * @return array<int,array{channel:string,address:string,metadata:array}>
     */
    private function filterEnabled(array $destinations): array
    {
        $result = [];
        foreach ($destinations as $d) {
            $channel = $d['channel'] ?? null;
            $address = $d['address'] ?? null;
            if (!is_string($channel) || $channel === '') continue;
            if (!is_string($address) || $address === '') continue;
            if (!$this->channelConfig($channel)['enabled']) continue;
            $result[] = ['channel' => $channel, 'address' => $address, 'metadata' => $d['metadata'] ?? []];
        }
        return $result;
    }

    private function defaultAddressForSeverity(string $channelKey, string $severity): ?string
    {
        $routes = config("notifications.severity_routes.$severity", []);
        if (!is_array($routes)) return null;
        foreach ($routes as $r) {
            if (($r['channel'] ?? null) === $channelKey && !empty($r['address'])) {
                return (string) $r['address'];
            }
        }
        // Fallback adicional: settings del canal en DB pueden traer default_address.
        $cfg = $this->channelConfig($channelKey);
        $default = $cfg['settings']['default_address'] ?? null;
        return is_string($default) && $default !== '' ? $default : null;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Extrae el subset que consideramos "credencial sensible" del config en disco.
     * Esto se usa solo si DB no tiene credenciales aún.
     */
    private function extractCredentialsFromFile(string $channelKey, array $fileConfig): array
    {
        return match ($channelKey) {
            'telegram' => [
                'bot_token' => $fileConfig['bot_token'] ?? null,
            ],
            default => [],
        };
    }

    /**
     * Extrae el subset "no sensible" (URLs, parse_mode, default address, etc.).
     */
    private function extractSettingsFromFile(string $channelKey, array $fileConfig): array
    {
        return match ($channelKey) {
            'telegram' => [
                'base_url'   => $fileConfig['base_url']   ?? 'https://api.telegram.org',
                'timeout'    => (int) ($fileConfig['timeout'] ?? 10),
                'parse_mode' => $fileConfig['parse_mode'] ?? 'MarkdownV2',
            ],
            default => $fileConfig,
        };
    }
}
