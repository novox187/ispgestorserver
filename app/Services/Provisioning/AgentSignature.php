<?php

namespace App\Services\Provisioning;

use Illuminate\Http\Request;

/**
 * Firma HMAC-SHA256 del canal máquina-a-máquina entre los agentes y la API.
 *
 * Se implementa a mano porque no hay nada reutilizable: los tokens de Sanctum
 * están atados a un usuario y no caducan, y el proyecto no tiene middleware de
 * URL firmadas ni receptores de webhook.
 *
 * La cadena canónica incluye método, ruta, marca de tiempo, nonce y hash del
 * cuerpo. Firmar el cuerpo impide alterar la instrucción en tránsito; el nonce
 * y la marca de tiempo impiden reproducir una petición capturada.
 *
 * El secreto nunca viaja: viaja la firma.
 */
class AgentSignature
{
    public const HEADER_AGENT     = 'X-ISPG-Agent';
    public const HEADER_TIMESTAMP = 'X-ISPG-Timestamp';
    public const HEADER_NONCE     = 'X-ISPG-Nonce';
    public const HEADER_SIGNATURE = 'X-ISPG-Signature';

    /** Desfase de reloj tolerado entre el agente y el servidor. */
    public const MAX_SKEW_SECONDS = 300;

    /**
     * Cuánto se recuerda un nonce. Debe ser al menos el doble del desfase
     * tolerado: una petición con marca T se acepta entre T-300 y T+300, así que
     * el registro tiene que sobrevivir esos 600 s completos o una repetición
     * tardía encontraría el nonce ya olvidado. Se deja margen sobre el mínimo.
     */
    public const NONCE_TTL_SECONDS = 900;

    private const ALGO = 'sha256';

    /**
     * Construye la cadena que ambas partes firman.
     *
     * @param string $path Ruta con query string si la hubiera (ej: /api/agent/tasks/claim)
     * @param string $body Cuerpo crudo tal cual se envía por el cable
     */
    public static function canonicalString(
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
    ): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            hash(self::ALGO, $body),
        ]);
    }

    public static function sign(
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body,
    ): string {
        return hash_hmac(
            self::ALGO,
            self::canonicalString($method, $path, $timestamp, $nonce, $body),
            $secret,
        );
    }

    /**
     * Ruta canónica de una petición entrante. Se incluye la query string para
     * que tampoco pueda manipularse, aunque hoy los agentes solo hagan POST con
     * el cuerpo en JSON.
     */
    public static function pathFor(Request $request): string
    {
        $path  = $request->getPathInfo();
        $query = $request->getQueryString();

        return $query === null || $query === '' ? $path : "{$path}?{$query}";
    }

    /**
     * Recalcula la firma esperada de una petición entrante.
     */
    public static function expectedFor(Request $request, string $secret): string
    {
        return self::sign(
            secret:    $secret,
            method:    $request->getMethod(),
            path:      self::pathFor($request),
            timestamp: (string) $request->header(self::HEADER_TIMESTAMP, ''),
            nonce:     (string) $request->header(self::HEADER_NONCE, ''),
            body:      $request->getContent(),
        );
    }

    /**
     * Comparación en tiempo constante: `===` sobre un HMAC filtra información
     * por el tiempo de retorno y permitiría reconstruir la firma byte a byte.
     */
    public static function matches(string $expected, string $provided): bool
    {
        return hash_equals($expected, $provided);
    }

    /**
     * Clave de caché del nonce. Va acotada por agente para que dos agentes no
     * puedan invalidarse mutuamente una petición legítima.
     */
    public static function nonceCacheKey(int $agentId, string $nonce): string
    {
        return "provisioning:agent:{$agentId}:nonce:" . hash(self::ALGO, $nonce);
    }
}
