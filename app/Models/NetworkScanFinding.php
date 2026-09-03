<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un equipo que respondió a un barrido.
 *
 * Es un CANDIDATO, no un dispositivo. Un barrido ve impresoras, portátiles y el
 * equipo del vecino además de las antenas; volcarlo todo al inventario llenaría
 * el mapa de ruido que después habría que limpiar a mano. El operador confirma
 * cuáles son suyos.
 *
 * Sin `Auditable`: un barrido produce decenas de estas de golpe y auditarlas
 * ahogaría el historial. Lo que sí se audita es el barrido que las originó y el
 * alta que salga de confirmar una.
 */
class NetworkScanFinding extends Model
{
    public const UPDATED_AT = null;

    /** Lo vio el barrido UDP del agente: responde el protocolo de Ubiquiti. */
    public const SOURCE_SWEEP = 'sweep';

    /** Salió de la tabla de vecinos de un router: habla MNDP, CDP o LLDP. */
    public const SOURCE_NEIGHBOR = 'neighbor';

    /** Lo vieron las dos. Es la señal más fuerte de que el equipo está vivo. */
    public const SOURCE_BOTH = 'both';

    protected $fillable = [
        'scan_id',
        'source',
        'mac_address',
        'ip_address',
        'vendor',
        'model',
        'firmware',
        'hostname',
        'essid',
        'matched_device_id',
        'discovered_via_device_id',
        'remote_interface',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function scan(): BelongsTo
    {
        return $this->belongsTo(NetworkScan::class, 'scan_id');
    }

    /** Equipo del inventario que ya corresponde a este hallazgo, si lo hay. */
    public function matchedDevice(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'matched_device_id');
    }

    /**
     * Equipo cuya tabla de vecinos reportó este hallazgo.
     *
     * Es el otro extremo del enlace: si un router ve a la antena, es que están
     * conectados. Por eso al dar de alta el hallazgo el enlace del mapa se
     * puede registrar solo, sin preguntarle al operador a qué está conectado.
     */
    public function discoveredVia(): BelongsTo
    {
        return $this->belongsTo(NetworkDevice::class, 'discovered_via_device_id');
    }

    public function isKnown(): bool
    {
        return $this->matched_device_id !== null;
    }
}
