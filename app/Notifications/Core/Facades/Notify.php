<?php

namespace App\Notifications\Core\Facades;

use App\Models\NotificationLog;
use App\Notifications\Core\Messages\NotificationMessage;
use App\Notifications\Core\NotificationDispatcher;
use Illuminate\Support\Facades\Facade;

/**
 * Fachada estática para enviar notificaciones desde cualquier punto de la app.
 *
 * @method static NotificationLog[] dispatch(NotificationMessage $message)
 *
 * @see NotificationDispatcher
 */
class Notify extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return NotificationDispatcher::class;
    }
}
