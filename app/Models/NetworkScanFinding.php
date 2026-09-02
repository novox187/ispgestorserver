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

    protected $fillable = [
        'scan_id',
        'mac_address',
        'ip_address',
        'vendor',
        'model',
        'firmware',
        'hostname',
        'essid',
        'matched_device_id',
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

    public function isKnown(): bool
    {
        return $this->matched_device_id !== null;
    }
}
