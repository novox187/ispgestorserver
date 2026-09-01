<?php

namespace App\Services\Provisioning\Vpn;

/**
 * Traduce la intención «monta este túnel» a instrucciones concretas para cada
 * extremo.
 *
 * La interfaz existe porque la compatibilidad con el parque de equipos puede
 * exigir mañana un segundo transporte: WireGuard necesita RouterOS 7.1+, y un
 * `L2tpIpsecDriver` cubriría los equipos que se queden en la rama 6.x. Hoy solo
 * está implementado WireGuard, pero la saga ya opera contra esta abstracción y
 * no contra `wg`, así que añadirlo no obliga a rehacer el orquestador.
 *
 * Todos los métodos devuelven listas de OPERACIONES tipadas —nunca comandos
 * crudos—. El agente valida cada `op` contra su propia lista blanca antes de
 * ejecutarla, de modo que ni siquiera un servidor comprometido puede hacerle
 * ejecutar algo arbitrario en la infraestructura de red.
 */
interface VpnDriver
{
    /** Identificador que se persiste en `router_vpn_profiles.driver`. */
    public function name(): string;

    /**
     * Operaciones que montan el túnel en el ROUTER.
     *
     * @param  array{api_username: string, api_password: string, api_port: int, subnet: string} $access
     *         Credenciales dedicadas que sustituirán a las de fábrica.
     * @return list<array<string,mixed>>
     */
    public function routerApplyOperations(TunnelSpec $spec, array $access): array;

    /**
     * Operaciones de endurecimiento del ROUTER, que se aplican una vez el túnel
     * está verificado: usuario dedicado con contraseña generada y cierre de la
     * API a la subred de gestión.
     *
     * @return list<array<string,mixed>>
     */
    public function routerHardenOperations(TunnelSpec $spec, array $access): array;

    /**
     * Operaciones que deshacen lo anterior en el ROUTER. Deben ser idempotentes:
     * el rollback puede dispararse sobre un equipo donde solo se aplicó parte.
     *
     * @return list<array<string,mixed>>
     */
    public function routerRollbackOperations(TunnelSpec $spec, array $access): array;

    /** @return list<array<string,mixed>> */
    public function routerVerifyOperations(TunnelSpec $spec): array;

    /**
     * Operaciones que registran el peer en el HOSTING.
     *
     * @param string $routerPublicKey Clave pública que el router generó por sí mismo.
     * @return list<array<string,mixed>>
     */
    public function hostApplyOperations(TunnelSpec $spec, string $routerPublicKey): array;

    /** @return list<array<string,mixed>> */
    public function hostRollbackOperations(TunnelSpec $spec, string $routerPublicKey): array;

    /** @return list<array<string,mixed>> */
    public function hostVerifyOperations(TunnelSpec $spec, string $routerPublicKey): array;
}
