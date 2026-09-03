<?php

namespace App\Services\Devices;

use App\Models\Client;
use App\Models\NetworkScanFinding;
use Illuminate\Support\Collection;

/**
 * Propone a qué abonado pertenece un equipo descubierto.
 *
 * Propone, no decide: quien confirma es el operador. La diferencia importa
 * porque equivocarse aquí ata el equipo de una persona a la ficha de otra, y
 * eso después se manifiesta como un corte de servicio al cliente que no tocaba.
 *
 * ## Dos señales, muy distintas de fiar
 *
 * - **La IP** (`clients.ip`) es exacta: es la dirección que la sincronización de
 *   colas usa para facturar a ese abonado, así que si coincide con la del
 *   equipo, es su equipo. En el parque real acertó en 13 de 19 clientes.
 * - **El nombre** es una pista, no una prueba. Los instaladores bautizan la
 *   antena con el nombre del cliente («ROBERTO ALVAREZ»), y eso empareja 11 de
 *   19 — pero dos hermanos con el mismo apellido, o un nombre reutilizado al
 *   cambiar de titular, producen una coincidencia falsa. Por eso el nombre solo
 *   se usa si la IP no dijo nada, y la respuesta lleva siempre el motivo para
 *   que la interfaz pueda enseñarlo.
 *
 * Se resuelve en memoria y no con una consulta por hallazgo: un barrido produce
 * más de cien candidatos y la cartera cabe holgadamente en un array.
 */
class ClientMatcher
{
    public const REASON_IP   = 'ip';
    public const REASON_NAME = 'name';

    /** IP que el sistema usa como «sin asignar»; no empareja con nada. */
    private const IP_SIN_ASIGNAR = '0.0.0.0';

    /** @var Collection<string, Client>|null */
    private ?Collection $porIp = null;

    /** @var Collection<string, Collection<int, Client>>|null */
    private ?Collection $porNombre = null;

    /**
     * @return array{client_id: int, client_name: string, reason: string}|null
     */
    public function suggest(NetworkScanFinding $finding): ?array
    {
        $this->cargar();

        $porIp = $this->porIp->get((string) $finding->ip_address);

        if ($porIp !== null) {
            return $this->respuesta($porIp, self::REASON_IP);
        }

        $nombre = $this->normalizar($finding->hostname);

        if ($nombre === null) {
            return null;
        }

        $candidatos = $this->porNombre->get($nombre);

        // Un solo candidato o ninguno. Con dos clientes que se llaman igual no
        // se propone a ninguno: elegir uno al azar sería peor que no proponer,
        // porque el operador confirmaría sin sospechar.
        if ($candidatos === null || $candidatos->count() !== 1) {
            return null;
        }

        return $this->respuesta($candidatos->first(), self::REASON_NAME);
    }

    private function respuesta(Client $client, string $reason): array
    {
        return [
            'client_id'   => $client->id,
            'client_name' => $client->full_name,
            'reason'      => $reason,
        ];
    }

    private function cargar(): void
    {
        if ($this->porIp !== null) {
            return;
        }

        $clientes = Client::query()->get(['id', 'full_name', 'ip']);

        $this->porIp = $clientes
            ->filter(fn (Client $c) => $c->ip && $c->ip !== self::IP_SIN_ASIGNAR)
            // `keyBy` se queda con el último si hay repetidos. Dos clientes con
            // la misma IP es un dato malo, pero no puede reventar un barrido.
            ->keyBy(fn (Client $c) => (string) $c->ip);

        $this->porNombre = $clientes
            ->filter(fn (Client $c) => $this->normalizar($c->full_name) !== null)
            ->groupBy(fn (Client $c) => $this->normalizar($c->full_name));
    }

    /**
     * Deja el nombre comparable: sin acentos, sin dobles espacios y en
     * mayúsculas, que es como los teclean los instaladores en las antenas.
     */
    private function normalizar(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim($valor);

        if ($texto === '') {
            return null;
        }

        $sinAcentos = strtr(
            $texto,
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
             'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'Ü' => 'U'],
        );

        return mb_strtoupper((string) preg_replace('/\s+/', ' ', $sinAcentos));
    }
}
