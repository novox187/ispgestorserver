<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InternetServiceProvider extends Model
{
    use HasFactory;

    /**
     * Los atributos que se pueden asignar masivamente.
     */
    protected $fillable = [
        'company_name',
        'technical_support_contact',
        'support_phone',
        'support_email',
        'address',
        'payment_method',
        'account_number',
        'is_active',
    ];

    /**
     * Obtener todas las conexiones/enlaces de este proveedor.
     * Un ISP puede tener múltiples líneas o enlaces contratados.
     */
    public function connections(): HasMany
    {
        return $this->hasMany(IspConnection::class, 'isp_id');
    }
}