<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    // Nombre de la tabla
    protected $table = 'planes';

    // Clave primaria
    protected $primaryKey = 'id_plan';

    // Campos que se pueden asignar masivamente (fillable)
    protected $fillable = [
        'nombre_plan',
        'velocidad_descarga_mbps',
        'velocidad_subida_mbps',
        'tarifa_mensual',
        'mikrotik_queue_name',
        'activo',
    ];

    /**
     * Un plan puede tener muchos servicios asociados.
     */
    public function servicios()
    {
        return $this->hasMany(Servicio::class, 'fk_id_plan', 'id_plan');
    }
}