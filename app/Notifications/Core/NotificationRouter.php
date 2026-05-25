<?php

namespace App\Notifications\Core;

use App\Notifications\Core\Messages\ChannelRecipient;
use App\Notifications\Core\Messages\NotificationMessage;

/**
 * Resuelve los destinatarios para un NotificationMessage dado.
 *
 * Delega en NotificationConfigRepository la lógica de precedencia DB > config,
 * de modo que las rutas configuradas desde el panel (notification_event_routes)
 * ganan sobre las declaradas en config/notifications.php.
 */
class NotificationRouter
{
    public function __construct(
        private readonly NotificationConfigRepository $configRepo,
    ) {
    }

    /**
     * @return array<int, ChannelRecipient>
     */
    public function resolve(NotificationMessage $message): array
    {
        $destinations = $this->configRepo->routesForCategory(
            $message->category,
            $message->severity->value
        );

        $recipients = [];
        foreach ($destinations as $d) {
            $recipients[] = new ChannelRecipient(
                channelKey: $d['channel'],
                address:    $d['address'],
                metadata:   $d['metadata'] ?? [],
            );
        }
        return $recipients;
    }
}
