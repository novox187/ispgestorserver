<?php

namespace App\Notifications\Core\Messages;

/**
 * Resultado de un intento de entrega de un canal.
 *
 * `shouldRetry` permite al canal distinguir errores transitorios (HTTP 5xx, 429,
 * timeouts) de errores definitivos (credenciales inválidas, destinatario inexistente).
 */
final class ChannelDeliveryResult
{
    public function __construct(
        public readonly bool    $success,
        public readonly ?string $externalId = null,
        public readonly ?string $error = null,
        public readonly bool    $shouldRetry = false,
    ) {
    }

    public static function success(?string $externalId = null): self
    {
        return new self(success: true, externalId: $externalId);
    }

    public static function permanentFailure(string $error): self
    {
        return new self(success: false, error: $error, shouldRetry: false);
    }

    public static function transientFailure(string $error): self
    {
        return new self(success: false, error: $error, shouldRetry: true);
    }
}
