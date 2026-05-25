<?php

namespace App\Notifications\Core\Contracts;

use App\Notifications\Core\Messages\NotificationMessage;

/**
 * Convierte un NotificationMessage genérico en la cadena específica que un canal
 * enviará al servicio remoto. Cada canal puede aportar su propio formatter.
 */
interface NotificationFormatter
{
    public function format(NotificationMessage $message): string;
}
