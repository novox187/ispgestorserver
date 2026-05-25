<?php

namespace App\Notifications\Core\Contracts;

use App\Notifications\Core\Messages\ChannelDeliveryResult;
use App\Notifications\Core\Messages\ChannelRecipient;
use App\Notifications\Core\Messages\NotificationMessage;

/**
 * Interfaz Strategy que todo canal de notificación debe implementar.
 *
 * Agregar soporte para un nuevo medio (email, SMS, Slack, push, etc.) consiste en
 * crear una clase que implemente esta interfaz y registrarla en config/notifications.php
 * bajo `channels`. El dispatcher la descubre mediante ChannelRegistry.
 */
interface NotificationChannel
{
    /**
     * Identificador único del canal (telegram, email, slack, ...).
     * Debe coincidir con la clave usada en config('notifications.channels').
     */
    public function key(): string;

    /**
     * Indica si el canal está habilitado y correctamente configurado.
     * Si retorna false, el dispatcher omite el envío y registra el log como `failed`.
     */
    public function isEnabled(): bool;

    /**
     * Permite al canal vetar tipos de mensajes que no puede entregar
     * (ej.: un canal SMS rechaza mensajes con adjuntos no soportados).
     */
    public function supports(NotificationMessage $message): bool;

    /**
     * Realiza el envío sincrónicamente.
     * No debe lanzar excepciones por errores de red — usar ChannelDeliveryResult::transientFailure().
     * Lanzar excepciones solo para bugs de programación (configuración incompleta, etc.).
     */
    public function send(NotificationMessage $message, ChannelRecipient $recipient): ChannelDeliveryResult;
}
