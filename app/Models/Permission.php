<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;

    protected $table = 'permissions';
    protected $primaryKey = 'id_permission';

    protected $fillable = [
        'nombre',
        'slug',
    ];

    public function roles()
    {
        return $this->belongsToMany(Rol::class, 'role_permission', 'fk_permission_id', 'fk_rol_id');
    }
}
