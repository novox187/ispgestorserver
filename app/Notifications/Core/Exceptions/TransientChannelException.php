<?php

namespace App\Notifications\Core\Exceptions;

/**
 * Excepción lanzada por SendNotificationJob para forzar el reintento de Laravel
 * cuando un canal reporta error transitorio (ChannelDeliveryResult::shouldRetry === true).
 */
class TransientChannelException extends \RuntimeException
{
}
