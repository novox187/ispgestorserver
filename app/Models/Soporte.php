<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Soporte extends Model
{
    use HasFactory;

    // Nombre de la tabla
    protected $table = 'soporte';

    // Clave primaria
    protected $primaryKey = 'id_ticket';

    // Campos que se pueden asignar masivamente
    protected $fillable = [
        'fk_id_cliente',
        'asunto',
        'descripcion',
        'prioridad',
        'estatus_ticket',
        'tecnico_asignado',
    ];
    
    /**
     * Un ticket de soporte pertenece a un cliente.
     */
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'fk_id_cliente', 'id_cliente');
    }
}