<?php

namespace App\Services\Provisioning\Vpn;

/**
 * Implementación WireGuard del túnel.
 *
 * Detalle importante del orden en que la saga aplica las cosas: la clave
 * pública del router no existe hasta que RouterOS crea la interfaz, y el peer
 * del hosting la necesita. Por eso se configura primero el router y después el
 * host, y no al revés.
 *
 * Ninguna operación transporta claves privadas. `wireguard.create_interface`
 * deja que RouterOS genere la suya y devuelva únicamente la pública; la del
 * servidor vive en el sistema de ficheros del hosting y el agente `vpn_host`
 * solo publica su contraparte.
 */
class WireGuardDriver implements VpnDriver
{
    /** Puerto de escucha por defecto de RouterOS para interfaces WireGuard. */
    private const ROUTER_LISTEN_PORT = 13231;

    public function name(): string
    {
        return 'wireguard';
    }

    public function routerApplyOperations(TunnelSpec $spec, array $access): array
    {
        return [
            // RouterOS genera la clave privada y devuelve la pública. Es la
            // razón por la que el router va primero en la secuencia.
            [
                'op'   => 'wireguard.create_interface',
                'name' => $spec->interfaceName,
                // Puerto de escucha PROPIO del router, no el del servidor: aquí
                // el router actúa de cliente detrás de NAT y nadie inicia
                // conexiones hacia él. Se fija al valor por defecto de RouterOS
                // para que el resultado sea reproducible.
                'listen_port' => self::ROUTER_LISTEN_PORT,
                // 1420 = 1500 − 80 de cabecera WireGuard sobre IPv4. Sin
                // bajarlo, la fragmentación rompe la sesión API por el túnel.
                'mtu'     => 1420,
                'comment' => 'ISP Gestor — alta automática',
            ],
            [
                'op'              => 'wireguard.add_peer',
                'interface'       => $spec->interfaceName,
                'public_key'      => $spec->serverPublicKey,
                'endpoint_address' => $spec->endpointHost,
                'endpoint_port'   => $spec->endpointPort,
                'allowed_address' => $spec->routerAllowedAddress(),
                // Imprescindible: el router está detrás del NAT de la oficina y
                // sin tráfico periódico el mapeo se cierra y el hosting deja de
                // poder iniciar conexiones hacia él.
                'keepalive'       => $spec->keepalive,
                'comment'         => 'ISP Gestor — servidor de gestión',
            ],
            [
                'op'        => 'ip.add_address',
                'address'   => $spec->addressWithPrefix(),
                'interface' => $spec->interfaceName,
                'comment'   => 'ISP Gestor — dirección de gestión',
            ],
            [
                'op'        => 'firewall.allow_input',
                'interface' => $spec->interfaceName,
                'ports'     => [$access['api_port']],
                'protocol'  => 'tcp',
                'comment'   => 'ISP Gestor — API por túnel',
            ],
            // El servicio API puede venir deshabilitado de fábrica; sin esto el
            // sistema tendría el túnel montado y aun así no podría hablar con
            // el equipo. Aquí se habilita SIN restringir origen: acotarlo a la
            // subred del túnel es cosa del endurecimiento, que ocurre cuando ya
            // no hace falta llegar por la LAN.
            [
                'op'   => 'service.enable_api',
                'port' => $access['api_port'],
            ],
        ];
    }

    /**
     * Endurecimiento del equipo, ejecutado DESPUÉS de verificar el túnel.
     *
     * Dejar el router con las credenciales de fábrica convertiría el
     * automatismo en un agujero de seguridad, así que se crea un usuario propio
     * con contraseña generada y se cierra la API a la subred de gestión.
     *
     * El orden importa: si esto se aplicase junto con la VPN, el agente de la
     * oficina perdería el acceso por la LAN y no podría verificar nada. Al
     * hacerlo al final, el corte de acceso solo ocurre cuando el túnel ya está
     * probado y el sistema puede entrar por él.
     *
     * No apila compensación propia: `routerRollbackOperations` ya borra el
     * usuario y las reglas, y es idempotente.
     *
     * @return list<array<string,mixed>>
     */
    public function routerHardenOperations(TunnelSpec $spec, array $access): array
    {
        return [
            [
                'op'              => 'user.create_api_user',
                'username'        => $access['api_username'],
                'password'        => $access['api_password'],
                'group'           => 'full',
                'allowed_address' => $spec->subnet,
                'comment'         => 'ISP Gestor — usuario de gestión',
            ],
            // Se comprueba que el usuario recién creado funciona ANTES de
            // cerrar la puerta por la que se entró: si algo salió mal, el
            // agente aún puede revertir con las credenciales de fábrica.
            [
                'op'       => 'user.verify_login',
                'username' => $access['api_username'],
                'password' => $access['api_password'],
                'port'     => $access['api_port'],
            ],
            [
                'op'              => 'service.restrict_api',
                'port'            => $access['api_port'],
                'allowed_address' => $spec->subnet,
            ],
        ];
    }

    public function routerRollbackOperations(TunnelSpec $spec, array $access): array
    {
        // Orden inverso al de aplicación y todas idempotentes: el rollback
        // puede caer sobre un equipo en el que solo se ejecutó parte de la
        // secuencia, así que borrar algo que no existe no puede ser un error.
        return [
            // Se reabre la API antes de nada: si el endurecimiento llegó a
            // aplicarse, sin este paso el agente no podría alcanzar al equipo
            // por la LAN para deshacer el resto.
            [
                'op'   => 'service.unrestrict_api',
                'port' => $access['api_port'],
            ],
            [
                'op'       => 'user.remove_api_user',
                'username' => $access['api_username'],
            ],
            [
                'op'        => 'firewall.remove_input_rules',
                'interface' => $spec->interfaceName,
            ],
            [
                'op'        => 'ip.remove_address',
                'interface' => $spec->interfaceName,
            ],
            [
                'op'        => 'wireguard.remove_peers',
                'interface' => $spec->interfaceName,
            ],
            [
                'op'   => 'wireguard.remove_interface',
                'name' => $spec->interfaceName,
            ],
        ];
    }

    public function routerVerifyOperations(TunnelSpec $spec): array
    {
        return [
            [
                'op'   => 'wireguard.check_interface',
                'name' => $spec->interfaceName,
            ],
            [
                'op'        => 'wireguard.check_peer_handshake',
                'interface' => $spec->interfaceName,
                'max_age_seconds' => 180,
            ],
            // La prueba de que el túnel no solo levanta sino que enruta.
            [
                'op'      => 'ping',
                'address' => $spec->serverIp,
                'count'   => 4,
                'interface' => $spec->interfaceName,
            ],
        ];
    }

    public function hostApplyOperations(TunnelSpec $spec, string $routerPublicKey): array
    {
        return [
            [
                'op'          => 'wg.set_peer',
                'interface'   => $spec->interfaceName,
                'public_key'  => $routerPublicKey,
                'allowed_ips' => $spec->peerAllowedIps(),
                'keepalive'   => $spec->keepalive,
            ],
            // Sin persistir, el peer desaparecería al reiniciar el host y el
            // router quedaría incomunicado sin que nada lo delatase hasta el
            // siguiente chequeo de salud.
            [
                'op'         => 'wg.persist_peer',
                'interface'  => $spec->interfaceName,
                'public_key' => $routerPublicKey,
                'allowed_ips' => $spec->peerAllowedIps(),
                'label'      => "ispgestor:{$spec->assignedIp}",
            ],
        ];
    }

    public function hostRollbackOperations(TunnelSpec $spec, string $routerPublicKey): array
    {
        return [
            [
                'op'         => 'wg.remove_peer',
                'interface'  => $spec->interfaceName,
                'public_key' => $routerPublicKey,
            ],
            [
                'op'         => 'wg.unpersist_peer',
                'interface'  => $spec->interfaceName,
                'public_key' => $routerPublicKey,
                'label'      => "ispgestor:{$spec->assignedIp}",
            ],
        ];
    }

    public function hostVerifyOperations(TunnelSpec $spec, string $routerPublicKey): array
    {
        return [
            [
                'op'              => 'wg.check_handshake',
                'interface'       => $spec->interfaceName,
                'public_key'      => $routerPublicKey,
                'max_age_seconds' => 180,
            ],
            [
                'op'      => 'ping',
                'address' => $spec->assignedIp,
                'count'   => 4,
            ],
        ];
    }
}
