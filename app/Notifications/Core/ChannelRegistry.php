<?php

namespace App\Notifications\Core;

use App\Notifications\Core\Contracts\NotificationChannel;
use App\Notifications\Core\Exceptions\ChannelNotConfiguredException;
use Illuminate\Contracts\Container\Container;

/**
 * Resuelve canales registrados en config/notifications.php.
 *
 * Mantiene un cache local de instancias para evitar reconstruir el cliente HTTP
 * (Telegram, etc.) en cada envío dentro de la misma ejecución.
 */
class ChannelRegistry
{
    /** @var array<string, NotificationChannel> */
    private array $resolved = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function has(string $key): bool
    {
        $config = config("notifications.channels.$key");
        return is_array($config) && !empty($config['driver']);
    }

    public function get(string $key): NotificationChannel
    {
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $config = config("notifications.channels.$key");
        if (!is_array($config) || empty($config['driver'])) {
            throw new ChannelNotConfiguredException(
                "Notification channel [$key] is not registered in config/notifications.php"
            );
        }

        $instance = $this->container->make($config['driver']);
        if (!$instance instanceof NotificationChannel) {
            throw new ChannelNotConfiguredException(
                "Channel driver [{$config['driver']}] must implement NotificationChannel."
            );
        }

        return $this->resolved[$key] = $instance;
    }

    /**
     * @return array<string, NotificationChannel>
     */
    public function all(): array
    {
        $all = [];
        foreach (array_keys(config('notifications.channels', [])) as $key) {
            $all[$key] = $this->get($key);
        }
        return $all;
    }
}
