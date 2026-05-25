<?php

namespace App\Notifications\Core\Exceptions;

/**
 * Se lanza cuando se solicita un canal que no está registrado o que carece de
 * configuración mínima válida (token, credenciales, etc.).
 */
class ChannelNotConfiguredException extends \RuntimeException
{
}
