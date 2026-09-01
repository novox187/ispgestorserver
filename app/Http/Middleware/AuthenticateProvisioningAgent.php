<?php

namespace App\Http\Middleware;

use App\Models\ProvisioningAgent;
use App\Services\Provisioning\AgentSignature;
use App\Services\Provisioning\ProvisioningAuditor;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica a un agente de aprovisionamiento mediante firma HMAC-SHA256.
 *
 * Este middleware es la frontera entre la aplicación y la infraestructura de
 * red: quien la cruza puede pedir que se cree un túnel VPN o que se toque la
 * configuración de un router. Por eso valida cuatro cosas y no solo la firma:
 *
 *  1. Que el agente exista y esté activo — revocar es efectivo al instante.
 *  2. Que el reloj no se desvíe más de lo tolerado, lo que acota la ventana en
 *     la que una petición capturada sigue siendo válida.
 *  3. Que el nonce no se haya visto antes, lo que cierra esa ventana del todo.
 *  4. Que la firma cuadre, comparada en tiempo constante.
 *
 * Cada rechazo se audita: un barrido de tokens contra este canal debe quedar
 * a la vista en el historial.
 *
 * Se registra con el alias `agent.hmac` en bootstrap/app.php.
 */
class AuthenticateProvisioningAgent
{
    /** Clave del atributo donde queda el agente para los controladores. */
    public const REQUEST_ATTRIBUTE = 'provisioning_agent';

    public function __construct(private readonly ProvisioningAuditor $auditor)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token     = (string) $request->header(AgentSignature::HEADER_AGENT, '');
        $timestamp = (string) $request->header(AgentSignature::HEADER_TIMESTAMP, '');
        $nonce     = (string) $request->header(AgentSignature::HEADER_NONCE, '');
        $signature = (string) $request->header(AgentSignature::HEADER_SIGNATURE, '');

        if ($token === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return $this->reject('AGENT_MISSING_HEADERS', null,
                'Faltan cabeceras de autenticación del agente.');
        }

        $agent = ProvisioningAgent::findByToken($token);

        if ($agent === null) {
            return $this->reject('AGENT_UNKNOWN_TOKEN', null,
                'Credenciales de agente no reconocidas.');
        }

        if (!$agent->is_active) {
            return $this->reject('AGENT_REVOKED', $agent,
                'El agente está revocado.');
        }

        if ($agent->secret === null) {
            return $this->reject('AGENT_NOT_ENROLLED', $agent,
                'El agente no ha completado su enrolamiento.');
        }

        if (!$this->timestampIsFresh($timestamp)) {
            return $this->reject('AGENT_CLOCK_SKEW', $agent,
                'La marca de tiempo está fuera de la ventana admitida.');
        }

        // La firma se valida ANTES de consumir el nonce: una petición que no
        // está autenticada no debe dejar rastro en el almacén de nonces, o
        // cualquiera con el token podría quemar los de un agente legítimo.
        $expected = AgentSignature::expectedFor($request, (string) $agent->secret);

        if (!AgentSignature::matches($expected, $signature)) {
            return $this->reject('AGENT_BAD_SIGNATURE', $agent,
                'La firma de la petición no es válida.');
        }

        // Cache::add es atómico: devuelve false si la clave ya existía, así que
        // de dos peticiones con el mismo nonce solo una puede pasar, ni
        // siquiera si llegan a la vez.
        $nonceIsNew = Cache::add(
            AgentSignature::nonceCacheKey($agent->id, $nonce),
            1,
            AgentSignature::NONCE_TTL_SECONDS,
        );

        if (!$nonceIsNew) {
            return $this->reject('AGENT_REPLAY', $agent,
                'La petición ya había sido procesada (nonce repetido).');
        }

        $agent->forceFill([
            'last_seen_at' => now(),
            'last_ip'      => $request->ip(),
        ])->saveQuietly();

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $agent);

        return $next($request);
    }

    /**
     * Se compara sobre enteros en vez de con `diffInSeconds` porque la firma y
     * el signo de ese método han cambiado entre versiones de Carbon; aquí no
     * hay margen para ambigüedades. Se usa `now()` y no `time()` para que los
     * tests puedan congelar el reloj.
     */
    private function timestampIsFresh(string $timestamp): bool
    {
        if (!ctype_digit($timestamp)) {
            return false;
        }

        $skew = abs(now()->getTimestamp() - (int) $timestamp);

        return $skew <= AgentSignature::MAX_SKEW_SECONDS;
    }

    private function reject(string $code, ?ProvisioningAgent $agent, string $message): JsonResponse
    {
        $this->auditor->authFailure($code, $agent);

        // Un único 401 con el código dentro: el agente necesita distinguir un
        // desfase de reloj (reintentar tras sincronizar) de una revocación
        // (dejar de intentarlo), pero nada de esto revela si el token existe.
        return response()->json([
            'error' => [
                'code'    => $code,
                'message' => $message,
            ],
        ], 401);
    }
}
