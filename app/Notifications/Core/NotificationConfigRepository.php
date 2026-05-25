<?php

namespace App\Notifications\Core;

use App\Models\NotificationChannelConfig;
use App\Models\NotificationEventRoute;
use App\Notifications\Core\Enums\NotificationCategory;
use Illuminate\Support\Facades\Schema;

/**
 * Fuente única de verdad para credenciales, settings y rutas del módulo
 * de notificaciones. Lee **exclusivamente** de la base de datos:
 *
 *   - `notification_channel_configs` para credenciales y settings por canal.
 *   - `notification_event_routes`    para rutas por categoría → canal/destinatario.
 *
 * No hay fallback a variables de entorno ni a config/notifications.php para
 * parámetros operados desde el panel. Si la BD no tiene fila o las tablas no
 * existen aún (entornos en provisión), el canal correspondiente queda
 * deshabilitado y el dispatcher registra el motivo en notification_logs.
 */
class NotificationConfigRepository
{
    /**
     * Devuelve la configuración de un canal tal como está almacenada en BD.
     *
     * @return array{enabled:bool, driver:?string, credentials:array, settings:array}
     */
    public function channelConfig(string $channelKey): array
    {
        // El driver (clase PHP) sí vive en config — es código, no parámetro de notificación.
        $driver = config("notifications.channels.$channelKey.driver");

        $empty = [
            'enabled'     => false,
            'driver'      => $driver,
            'credentials' => [],
            'settings'    => [],
        ];

        if (!$this->tableExists('notification_channel_configs')) {
            return $empty;
        }

        $dbRow = NotificationChannelConfig::forChannel($channelKey);

        if (!$dbRow) {
            return $empty;
        }

        return [
            'enabled'     => (bool) $dbRow->enabled,
            'driver'      => $driver,
            'credentials' => (array) ($dbRow->credentials ?? []),
            'settings'    => (array) ($dbRow->settings ?? []),
        ];
    }

    /**
     * Resuelve las rutas para una categoría a partir de `notification_event_routes`.
     * Si una ruta no tiene `address_override`, cae al `settings.default_address`
     * del canal — ambos almacenados en BD.
     *
     * Si no hay rutas configuradas para la categoría, retorna array vacío y el
     * dispatcher escribirá un NotificationLog con error "no recipients".
     *
     * @return array<int, array{channel:string,address:string,metadata:array}>
     */
    public function routesForCategory(NotificationCategory $category, string $severity): array
    {
        if (!$this->tableExists('notification_event_routes')) {
            return [];
        }

        $dbRoutes = NotificationEventRoute::cached()
            ->where('category', $category->value)
            ->where('enabled', true)
            ->values();

        $resolved = [];
        foreach ($dbRoutes as $route) {
            $channelCfg = $this->channelConfig($route->channel_key);
            if (!$channelCfg['enabled']) {
                continue;
            }

            $address = $route->address_override
                ?: ($channelCfg['settings']['default_address'] ?? null);

            if (!is_string($address) || $address === '') {
                continue;
            }

            $resolved[] = [
                'channel'  => $route->channel_key,
                'address'  => $address,
                'metadata' => (array) ($route->extra ?? []),
            ];
        }

        return $resolved;
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
