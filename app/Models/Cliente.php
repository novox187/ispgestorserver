<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Cliente extends Authenticatable
{
    use HasApiTokens, HasFactory;

    // Nombre de la tabla
    protected $table = 'clientes';

    // Clave primaria
    protected $primaryKey = 'id_cliente';

    // Campos que se pueden asignar masivamente
    protected $fillable = [
        'nombre_completo',
        'documento_id',
        'telefono_contacto',
        'email',
        'password',
        'direccion_instalacion',
        'coordenadas_gps',
        'fecha_contratacion',
        'estatus_servicio',
        'observaciones',
        'ip',
    ];

    // Campos ocultos para arrays/JSON
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Usar la PK personalizada para el route model binding.
     */
    public function getRouteKeyName(): string
    {
        return 'id_cliente';
    }

    /**
     * Un cliente tiene un servicio (relación 1:1, aunque podría ser 1:N si permites múltiples servicios).
     */
    public function servicio()
    {
        return $this->hasOne(Servicio::class, 'fk_id_cliente', 'id_cliente');
    }

    /**
     * Un cliente puede tener muchos tickets de soporte.
     */
    public function soportes()
    {
        return $this->hasMany(Soporte::class, 'fk_id_cliente', 'id_cliente');
    }
}