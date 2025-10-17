<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rol extends Model
{
    use HasFactory;

    protected $table = 'roles';
    protected $primaryKey = 'id_rol';

    protected $fillable = [
        'nombre',
        'slug',
        'descripcion',
    ];

    public function administradores()
    {
        return $this->hasMany(Administrador::class, 'fk_rol_id', 'id_rol');
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permission', 'fk_rol_id', 'fk_permission_id');
    }
}
