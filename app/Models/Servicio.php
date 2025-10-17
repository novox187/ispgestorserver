<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Servicio extends Model
{
    use HasFactory;

    // Nombre de la tabla
    protected $table = 'servicios';

    // Clave primaria
    protected $primaryKey = 'id_servicio';
    
    // Desactivar timestamps si no son necesarios, pero se mantienen por defecto en la migración.
    // public $timestamps = false; 

    // Campos que se pueden asignar masivamente
    protected $fillable = [
        'fk_id_cliente',
        'fk_id_plan',
        'metodo_autenticacion',
        'ip_estatica_cliente',
        'mac_address_cliente',
        'dia_corte_fijo',
        'fecha_proximo_vencimiento',
        'fecha_ultimo_pago',
    ];

    // Casts para asegurar que las fechas sean tratadas como objetos de fecha
    protected $casts = [
        'fecha_proximo_vencimiento' => 'date',
        'fecha_ultimo_pago' => 'date',
    ];

    /**
     * Un servicio pertenece a un cliente.
     */
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'fk_id_cliente', 'id_cliente');
    }

    /**
     * Un servicio tiene un plan asociado.
     */
    public function plan()
    {
        return $this->belongsTo(Plan::class, 'fk_id_plan', 'id_plan');
    }
}