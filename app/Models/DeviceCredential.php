<?php

namespace App\Models;

use App\Enums\DeviceVendor;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un juego de credenciales compartido por varios equipos.
 *
 * Existe porque un WISP administra sus antenas en bloque: la misma clave en las
 * trescientas. Con una copia por fila, rotarla sería editar trescientos
 * registros y bastaría olvidar uno para dejar un equipo fuera del monitoreo sin
 * que nadie se entere.
 *
 * No sustituye a las credenciales propias de cada equipo, las complementa: los
 * routers dados de alta por el flujo automático tienen contraseña generada e
 * individual, y eso debe seguir así.
 */
class DeviceCredential extends Model
{
    use Auditable;

    protected $fillable = [
        'name',
        'vendor',
        'username',
        'password',
        'description',
        'rotated_at',
    ];

    /** Nunca sale en una respuesta de la API. */
    protected $hidden = ['password'];

    protected $casts = [
        'vendor'     => DeviceVendor::class,
        'password'   => 'encrypted',
        'rotated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Cambiar la contraseña sella la fecha por su cuenta: si dependiera de
        // que alguien se acuerde de rellenarla, el dato no valdría nada.
        static::saving(function (self $profile) {
            if ($profile->isDirty('password')) {
                $profile->rotated_at = now();
            }
        });
    }

    public function devices(): HasMany
    {
        return $this->hasMany(NetworkDevice::class, 'credential_profile_id');
    }
}
