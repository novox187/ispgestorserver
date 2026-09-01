<?php

namespace App\Services\Provisioning;

use App\Models\DeviceProvisioningSession;
use App\Models\RouterVpnProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reparte las direcciones de la subred VPN entre los routers.
 *
 * Dos altas simultáneas que reciban la misma IP producirían un túnel que
 * aparenta funcionar y enruta mal, que es el peor fallo posible aquí: silencioso
 * y difícil de diagnosticar. Por eso hay tres barreras:
 *
 *  1. La asignación ocurre dentro de una transacción con `lockForUpdate` sobre
 *     los perfiles que retienen dirección.
 *  2. La columna `assigned_ip` tiene índice único.
 *  3. Revocar un perfil pone `assigned_ip` a null y mueve el valor a
 *     `released_ip`; MySQL admite varios NULL en un índice único, así que la
 *     dirección vuelve al pool sin perder el rastro de quién la ocupó.
 */
class VpnAddressAllocator
{
    /**
     * Cerrojo global de asignación. Es de grano grueso —una asignación a la
     * vez en todo el sistema— y está bien que lo sea: dar de alta un router es
     * un evento raro y la operación dura milisegundos.
     */
    private const LOCK_KEY          = 'provisioning:vpn-address-allocation';
    private const LOCK_SECONDS      = 10;
    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(private readonly ProvisioningSettings $settings)
    {
    }

    /**
     * Reserva una dirección para una sesión y se la escribe.
     *
     * Reservar y persistir van juntos y bajo cerrojo a propósito. Una sesión en
     * vuelo retiene su dirección en `device_provisioning_sessions`, no en
     * `router_vpn_profiles` —ahí solo aparece al completarse el alta—, así que
     * el índice único de esa tabla no protege durante el proceso. Sin el
     * cerrojo, dos altas simultáneas podrían leer «la .5 está libre» y quedarse
     * las dos con ella; el resultado sería un túnel que levanta y enruta mal,
     * que es el peor fallo posible aquí: silencioso.
     *
     * @throws RuntimeException si la subred es inválida o está agotada.
     */
    public function allocateFor(DeviceProvisioningSession $session): string
    {
        if (filled($session->vpn_assigned_ip)) {
            return (string) $session->vpn_assigned_ip;
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        return $lock->block(self::LOCK_WAIT_SECONDS, function () use ($session) {
            // Se relee dentro del cerrojo por si otra ejecución de la saga sobre
            // la misma sesión ya asignó.
            $session->refresh();
            if (filled($session->vpn_assigned_ip)) {
                return (string) $session->vpn_assigned_ip;
            }

            $ip = $this->nextFreeAddress();
            $session->forceFill(['vpn_assigned_ip' => $ip])->save();

            return $ip;
        });
    }

    /**
     * Siguiente dirección libre, mirando las dos fuentes que pueden retener
     * una: los perfiles activos y las sesiones de alta todavía en curso.
     *
     * @param  list<string> $alsoReserved Direcciones a tratar como ocupadas.
     * @throws RuntimeException
     */
    public function nextFreeAddress(array $alsoReserved = []): string
    {
        $subnet = $this->settings->vpnSubnet();
        $range  = $this->hostRange($subnet);

        $reserved   = array_map('strval', $alsoReserved);
        $reserved[] = $this->settings->vpnServerIp();

        return DB::transaction(function () use ($range, $reserved, $subnet) {
            $fromProfiles = RouterVpnProfile::query()
                ->whereNotNull('assigned_ip')
                ->lockForUpdate()
                ->pluck('assigned_ip')
                ->all();

            $fromSessions = DeviceProvisioningSession::query()
                ->active()
                ->whereNotNull('vpn_assigned_ip')
                ->pluck('vpn_assigned_ip')
                ->all();

            $takenLong = [];
            foreach (array_merge($fromProfiles, $fromSessions, $reserved) as $ip) {
                $long = ip2long((string) $ip);
                if ($long !== false) {
                    $takenLong[$long] = true;
                }
            }

            for ($candidate = $range['first']; $candidate <= $range['last']; $candidate++) {
                if (!isset($takenLong[$candidate])) {
                    return long2ip($candidate);
                }
            }

            throw new RuntimeException(
                "La subred VPN {$subnet} está agotada: no quedan direcciones libres."
            );
        });
    }

    /**
     * Libera la dirección de un perfil devolviéndola al pool.
     */
    public function release(RouterVpnProfile $profile): void
    {
        $profile->revoke();
    }

    /**
     * Direcciones asignables de una subred CIDR, excluyendo la de red y la de
     * difusión.
     *
     * @return array{first: int, last: int}
     * @throws RuntimeException
     */
    public function hostRange(string $cidr): array
    {
        if (!str_contains($cidr, '/')) {
            throw new RuntimeException("Subred VPN inválida: '{$cidr}' no está en formato CIDR.");
        }

        [$network, $bits] = explode('/', $cidr, 2);

        $networkLong = ip2long($network);
        $prefix      = (int) $bits;

        if ($networkLong === false || $prefix < 8 || $prefix > 30) {
            throw new RuntimeException(
                "Subred VPN inválida: '{$cidr}'. Se admite un prefijo entre /8 y /30."
            );
        }

        $mask  = -1 << (32 - $prefix);
        $base  = $networkLong & $mask;
        $size  = 1 << (32 - $prefix);

        return [
            'first' => $base + 1,           // .0 es la dirección de red
            'last'  => $base + $size - 2,   // la última es la de difusión
        ];
    }

    /**
     * Máscara en notación CIDR de la subred configurada, para componer la
     * dirección que se escribe en el router (ej: 10.77.0.5/24).
     */
    public function prefixLength(): int
    {
        $subnet = $this->settings->vpnSubnet();

        return (int) explode('/', $subnet, 2)[1];
    }

    public function isWithinSubnet(string $ip): bool
    {
        $range = $this->hostRange($this->settings->vpnSubnet());
        $long  = ip2long($ip);

        return $long !== false && $long >= $range['first'] && $long <= $range['last'];
    }
}
