<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Support extends Model
{
    use HasFactory;

    // Nombre de la tabla
    protected $table = 'supports';

    // Clave primaria
    protected $primaryKey = 'id_ticket';

    // Campos que se pueden asignar masivamente
    protected $fillable = [
        'fk_id_cliente',
        'subject',
        'description',
        'priority',
        'status',
        'assigned_technician',
    ];
    
    /**
     * Un ticket de soporte pertenece a un cliente.
     */
    public function cliente()
    {
        // Relación correcta: fk en supports -> id en clients
        return $this->belongsTo(Client::class, 'fk_id_cliente', 'id');
    }   
}