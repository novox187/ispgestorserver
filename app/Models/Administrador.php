<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Administrador extends Authenticatable
{
    use HasFactory;

    protected $table = 'administradores';

    protected $primaryKey = 'id_admin';

    protected $fillable = [
        'nombre',
        'email',
        'password',
        'telefono',
        'fk_rol_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function rol()
    {
        return $this->belongsTo(Rol::class, 'fk_rol_id', 'id_rol');
    }
}
