<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    protected $table = 'roles';
    protected $primaryKey = 'id';

    protected $fillable = [
        'nombre',
        'slug',
        'descripcion',
    ];

    public function administradores()
    {
        return $this->hasMany(Employee::class, 'role_id', 'id');
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permission', 'fk_role_id', 'fk_permission_id');
    }

    public function hasPermission(string $slug): bool
    {
        return $this->permissions->contains('slug', $slug);
    }
}
